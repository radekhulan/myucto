<script setup lang="ts">
/*
 * Evidenční list důchodového pojištění — VÝJIMKA, ne roční rutina.
 *
 * Od roku 2026 zaměstnavatel evidenční list nevyhotovuje ani nepředkládá:
 * údaje pro důchodové pojištění sděluje jednotným měsíčním hlášením a list
 * z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona č. 582/1991 Sb. ve znění
 * zák. č. 360/2025 Sb.); zaměstnanci je dostupný na ePortálu (§ 39 odst. 1).
 * Panel proto NEVEDE nikoho k tomu, aby „odbavil ELDP za loňský rok" —
 * přípustnost si vyžádá od serveru dřív, než dá vyplnit potvrzení, a když
 * povinnost nevznikla, řekne to a přípravu nedovolí.
 *
 * Žádné tlačítko tady neodesílá — lokální podání se zastaví ve stavu
 * „připraveno" a nabízí jen kontrolní XML. Člověk podání dokončí v oficiálním
 * rozhraní ČSSZ a tady následně uloží jen doložený výsledek z firemního DMS.
 */
import { computed, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  payrollApi,
  type PayrollEldpAuthorityStatus,
  type PayrollEldpEligibility,
  type PayrollEldpManualCompletionOverview,
  type PayrollEldpPrepared,
  type PayrollEldpStatement,
  type PayrollEmployment,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const preparing = ref(false)
const downloading = ref(false)
const employments = ref<PayrollEmployment[]>([])
const personId = ref<number | null>(null)
const employmentId = ref<number | null>(null)
/*
 * Poslední rok, za který zaměstnavatel evidenční list vyhotovoval za celý
 * kalendářní rok. Zrcadlí `EldpDeadlinePolicy::LAST_ANNUAL_YEAR` — server je
 * jediná autorita, ale výchozí rok se musí zvolit dřív, než dorazí odpověď.
 * Bez téhle meze by panel od roku 2027 sám předvyplňoval „loni" a znovu tak
 * nabízel roční povinnost, která od roku 2026 neexistuje.
 */
const LAST_ANNUAL_ELDP_YEAR = 2025
const year = ref<number>(
  Math.min(new Date().getFullYear() - 1, LAST_ANNUAL_ELDP_YEAR),
)
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
const excludedDaysConfirmed = ref(false)
const deductedDaysNone = ref(false)
const requestedByAuthority = ref(false)
const authorityRequestReceivedOn = ref('')
const note = ref('')
const statement = ref<PayrollEldpStatement | null>(null)
const eligibility = ref<PayrollEldpEligibility | null>(null)
const prepared = ref<PayrollEldpPrepared | null>(null)
const blockers = ref<Array<{ code: string, message: string }>>([])
const error = ref('')
const success = ref('')
const downloadError = ref('')
const manualCompletion = ref<PayrollEldpManualCompletionOverview | null>(null)
const authorityStatus = ref<PayrollEldpAuthorityStatus>('submitted')
const confirmationDocumentQuery = ref('')
const confirmationDocuments = ref<DocItem[]>([])
const confirmationDocument = ref<DocItem | null>(null)
const authorityReference = ref('')
const confirmedOn = ref(new Date().toLocaleDateString('sv-SE'))
const completing = ref(false)
const completionError = ref('')
const completionSuccess = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const canReadDocuments = computed(() => auth.canRead('documents'))
const hasAcceptedEvidence = computed(() => manualCompletion.value?.evidence
  .some(item => item.authority_status === 'accepted') ?? false)
const hasSelectedStatusEvidence = computed(() => manualCompletion.value?.evidence
  .some(item => item.authority_status === authorityStatus.value) ?? false)
const canComplete = computed(() =>
  canWrite.value
  && canReadDocuments.value
  && !completing.value
  && statement.value !== null
  && manualCompletion.value !== null
  && confirmationDocument.value !== null
  && authorityReference.value.trim().length >= 1
  && authorityReference.value.trim().length <= 190
  && /^\d{4}-\d{2}-\d{2}$/.test(confirmedOn.value)
  && !hasAcceptedEvidence.value
  && !hasSelectedStatusEvidence.value)
const employmentOptions = computed(() =>
  employments.value.map(employment => ({
    value: employment.id,
    label: employment.end_date
      ? `${employment.code} (${employment.start_date ?? '?'} – ${employment.end_date})`
      : `${employment.code} (${employment.start_date ?? '?'})`,
  })))
const environmentOptions = computed(() => [
  { value: 'production' as PayrollRegzelEnvironment, label: t('payroll.regzel.environment.production') },
  { value: 'test' as PayrollRegzelEnvironment, label: t('payroll.regzel.environment.test') },
])
const yearOptions = computed(() => {
  const current = new Date().getFullYear()
  return Array.from({ length: 6 }, (_, index) => current - index)
    .map(value => ({ value, label: String(value) }))
})
/*
 * Fail-closed: dokud server nepotvrdí, že samostatný evidenční list za tenhle
 * rozsah vůbec vzniká, příprava se nenabízí. Jedinou cestou přes zákaz je
 * výzva ČSSZ/ÚSSZ (§ 38a odst. 2 a 3), a tu uživatel dokládá datem doručení —
 * není to odklikávací výjimka, ale skutečná událost.
 */
const standaloneAllowed = computed(() =>
  eligibility.value !== null
  && (eligibility.value.allowed || requestedByAuthority.value))
/* Roční rutina existuje jen pro období před rokem 2026; jinde je to výjimka. */
const isRoutineYear = computed(() => eligibility.value?.routine === true)
/*
 * Obě potvrzení musí padnout výslovně. Vyloučené doby mění osobní vyměřovací
 * základ a odečítané doby po dosažení důchodového věku modul neumí odvodit —
 * proto je nula podmíněná potvrzením, ne výpočtem.
 */
const canPrepare = computed(() =>
  canWrite.value
  && !preparing.value
  && employmentId.value !== null
  && standaloneAllowed.value
  && excludedDaysConfirmed.value
  && deductedDaysNone.value
  && (!requestedByAuthority.value || authorityRequestReceivedOn.value !== '')
  && note.value.trim().length >= 5
  && note.value.trim().length <= 500)

async function loadEmployments(id: number): Promise<void> {
  employments.value = []
  employmentId.value = null
  try {
    const person = await payrollApi.person(id)
    employments.value = person.employments
    if (employments.value.length === 1) {
      employmentId.value = employments.value[0].id
    }
  } catch {
    error.value = t('payroll.eldp.errors.loadFailed')
  }
}

async function loadStatement(): Promise<void> {
  statement.value = null
  eligibility.value = null
  if (employmentId.value === null) {
    return
  }
  try {
    const response = await payrollApi.eldpStatement({
      employment_id: employmentId.value,
      year: year.value,
      environment: environment.value,
    })
    statement.value = response.statement
    // Fail-closed i proti starší odpovědi bez přípustnosti: bez ní se příprava
    // nenabízí, protože bychom nevěděli, jestli povinnost vůbec vznikla.
    eligibility.value = response.eligibility ?? null
    manualCompletion.value = response.manual_completion
  } catch {
    statement.value = null
    eligibility.value = null
    manualCompletion.value = null
  }
}

async function searchConfirmationDocuments(): Promise<void> {
  const query = confirmationDocumentQuery.value.trim()
  if (query.length < 2) {
    confirmationDocuments.value = []
    return
  }
  completionError.value = ''
  try {
    confirmationDocuments.value = (await documentsApi.search(query))
      .filter(document => document.scope !== 'user' && document.deleted_at === null)
  } catch {
    confirmationDocuments.value = []
    completionError.value = t('payroll.eldp.manual.errors.documentSearchFailed')
  }
}

function chooseConfirmationDocument(document: DocItem): void {
  confirmationDocument.value = document
  confirmationDocumentQuery.value = document.title
  confirmationDocuments.value = []
}

function clearConfirmationDocument(): void {
  confirmationDocument.value = null
  confirmationDocumentQuery.value = ''
  confirmationDocuments.value = []
}

async function completeManually(): Promise<void> {
  if (!canComplete.value || statement.value === null || manualCompletion.value === null
    || confirmationDocument.value === null
  ) return
  /*
   * Dialog tady zůstává: doložením se tvrdí, co se stalo VENKU, u ČSSZ.
   * Aplikace to nemůže vzít zpět — evidenci nelze smazat a nepravdivé doložení
   * by prohlásilo povinnost za splněnou. Musí ale říct, čeho se týká: panel se
   * přepíná mezi vztahy i roky a doložení u špatného ELDP vypadá při obecné
   * otázce stejně jako u správného.
   */
  const employment = employmentOptions.value
    .find(option => option.value === employmentId.value)
  if (!window.confirm(t('payroll.eldp.manual.confirmFor', {
    question: t(`payroll.eldp.manual.confirm.${authorityStatus.value}`),
    employment: employment?.label ?? t('payroll.eldp.manual.employmentUnknown'),
    year: year.value,
  }))) return

  completing.value = true
  completionError.value = ''
  completionSuccess.value = ''
  try {
    const result = await payrollApi.completeEldp(statement.value.id, {
      environment: environment.value,
      expected_obligation_row_version: manualCompletion.value.obligation_row_version,
      authority_status: authorityStatus.value,
      confirmation_document_id: confirmationDocument.value.id,
      authority_reference: authorityReference.value.trim(),
      confirmed_on: confirmedOn.value,
      idempotency_key: `eldp-manual:${environment.value}:${statement.value.id}:${authorityStatus.value}`,
    })
    completionSuccess.value = t(`payroll.eldp.manual.saved.${result.authority_status}`)
    clearConfirmationDocument()
    authorityReference.value = ''
    await loadStatement()
  } catch (exception) {
    if (isAxiosError(exception)) {
      const payload = exception.response?.data?.error
      completionError.value = typeof payload?.message === 'string'
        ? payload.message
        : t('payroll.eldp.manual.errors.saveFailed')
    } else {
      completionError.value = t('payroll.eldp.manual.errors.saveFailed')
    }
  } finally {
    completing.value = false
  }
}

async function prepare(): Promise<void> {
  if (employmentId.value === null || !canPrepare.value) {
    return
  }
  preparing.value = true
  error.value = ''
  success.value = ''
  blockers.value = []
  try {
    prepared.value = await payrollApi.prepareEldp({
      employment_id: employmentId.value,
      year: year.value,
      environment: environment.value,
      excluded_days_confirmed: excludedDaysConfirmed.value,
      deducted_days_none: deductedDaysNone.value,
      requested_by_authority: requestedByAuthority.value,
      authority_request_received_on: requestedByAuthority.value
        ? authorityRequestReceivedOn.value
        : null,
      note: note.value.trim(),
      idempotency_key: `eldp:${environment.value}:${employmentId.value}:${year.value}`,
    })
    success.value = prepared.value.created
      ? t('payroll.eldp.preparedCreated')
      : t('payroll.eldp.preparedReplayed')
    await loadStatement()
  } catch (exception) {
    if (isAxiosError(exception)) {
      const payload = exception.response?.data?.error
      error.value = typeof payload?.message === 'string'
        ? payload.message
        : t('payroll.eldp.errors.prepareFailed')
      blockers.value = Array.isArray(payload?.blockers) ? payload.blockers : []
    } else {
      error.value = t('payroll.eldp.errors.prepareFailed')
    }
  } finally {
    preparing.value = false
  }
}

async function downloadControlXml(): Promise<void> {
  if (!prepared.value || downloading.value) return
  downloading.value = true
  downloadError.value = ''
  try {
    const detail = await payrollApi.submissionDetail(prepared.value.submission_id)
    const artifact = detail.artifacts.find(item => item.id === prepared.value?.artifact_id)
    if (!artifact) {
      throw new Error('ELDP artifact is missing.')
    }
    await payrollApi.downloadSubmissionArtifact(prepared.value.submission_id, artifact)
  } catch {
    downloadError.value = t('payroll.eldp.errors.downloadFailed')
  } finally {
    downloading.value = false
  }
}

watch(personId, value => {
  if (value !== null) {
    void loadEmployments(value)
  } else {
    employments.value = []
    employmentId.value = null
  }
})
watch([employmentId, year, environment], () => {
  prepared.value = null
  manualCompletion.value = null
  completionError.value = ''
  completionSuccess.value = ''
  clearConfirmationDocument()
  blockers.value = []
  void loadStatement()
})
watch(requestedByAuthority, value => {
  if (value && authorityRequestReceivedOn.value === '') {
    const today = new Date()
    authorityRequestReceivedOn.value = [
      today.getFullYear(),
      String(today.getMonth() + 1).padStart(2, '0'),
      String(today.getDate()).padStart(2, '0'),
    ].join('-')
  }
})
</script>

<template>
  <div class="space-y-4" data-test="eldp-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-700">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.eldp.title') }}
      </h3>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.eldp.intro') }}
      </p>
      <ul class="mt-2 max-w-prose list-disc space-y-1 pl-5 text-sm">
        <li>{{ t('payroll.eldp.exceptions.beforeTwentySix') }}</li>
        <li>{{ t('payroll.eldp.exceptions.endedBeforeApril') }}</li>
        <li>{{ t('payroll.eldp.exceptions.authorityRequest') }}</li>
      </ul>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.eldp.legalBasis') }}
      </p>
    </div>

    <!--
      Stav přípustnosti stojí nad formulářem, ne pod tlačítkem: kdyby se
      obsluha dozvěděla až z chyby po vyplnění potvrzení, že povinnost
      nevznikla, naučí se hlášku odklikávat jako překážku místo číst ji
      jako pravidlo.
    -->
    <div
      v-if="eligibility && !standaloneAllowed"
      data-test="eldp-not-applicable"
      class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-800"
      role="status"
    >
      <p class="font-medium">{{ t('payroll.eldp.notApplicable.title') }}</p>
      <p class="mt-1 max-w-prose">{{ eligibility.reason }}</p>
      <p v-if="eligibility.authority_request_available" class="mt-2 max-w-prose">
        {{ t('payroll.eldp.notApplicable.authorityHint') }}
      </p>
    </div>
    <div
      v-else-if="eligibility && !isRoutineYear"
      data-test="eldp-exception"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700"
      role="status"
    >
      <p class="font-medium">{{ t('payroll.eldp.exceptionOnly.title') }}</p>
      <p class="mt-1 max-w-prose">{{ eligibility.reason }}</p>
    </div>

    <div
      v-if="error"
      data-test="eldp-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      {{ error }}
      <ul v-if="blockers.length" class="mt-2 list-disc space-y-1 pl-5">
        <li v-for="blocker in blockers" :key="blocker.code" data-test="eldp-blocker">
          {{ blocker.message }}
        </li>
      </ul>
    </div>

    <div
      v-if="success"
      data-test="eldp-success"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      role="status"
    >
      {{ success }}
    </div>

    <div class="space-y-4 rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.person') }}
          </span>
          <PayrollPersonSearchSelect
            v-model="personId"
            data-test="eldp-person"
            :label="t('payroll.eldp.person')"
            :clearable="false"
          />
        </div>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.employment') }}
          </span>
          <SearchableSelect v-model="employmentId" :options="employmentOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.year') }}
          </span>
          <SearchableSelect v-model="year" :options="yearOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.regzel.environment.label') }}
          </span>
          <SearchableSelect v-model="environment" :options="environmentOptions" />
        </label>
      </div>

      <div class="space-y-2 border-t border-neutral-200 pt-4">
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="excludedDaysConfirmed"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-excluded-confirm"
          >
          <span>{{ t('payroll.eldp.confirmExcluded') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="deductedDaysNone"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-deducted-confirm"
          >
          <span>{{ t('payroll.eldp.confirmDeducted') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="requestedByAuthority"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-authority-request"
          >
          <span>{{ t('payroll.eldp.requestedByAuthority') }}</span>
        </label>
        <label v-if="requestedByAuthority" class="block max-w-sm text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.authorityRequestReceivedOn') }}
          </span>
          <input
            v-model="authorityRequestReceivedOn"
            type="date"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            data-test="eldp-authority-request-date"
          >
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.eldp.authorityRequestReceivedOnHint') }}
          </span>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.note') }}
          </span>
          <textarea
            v-model="note"
            rows="2"
            maxlength="500"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
            data-test="eldp-note"
          />
        </label>
      </div>

      <div
        v-if="statement"
        class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm"
        data-test="eldp-summary"
      >
        <dl class="grid gap-2 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.period') }}</dt>
            <dd class="font-medium">{{ statement.period_from }} – {{ statement.period_to }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.insuranceDays') }}</dt>
            <dd class="font-medium">{{ statement.insurance_days }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.excludedDays') }}</dt>
            <dd class="font-medium">{{ statement.excluded_days_total }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.kind') }}</dt>
            <dd class="font-medium">
              {{ t(`payroll.eldp.kind.${statement.statement_kind}`) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.dueOn') }}</dt>
            <dd class="font-medium">{{ statement.due_on }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.sections') }}</dt>
            <dd class="font-medium">{{ statement.section_count }}</dd>
          </div>
        </dl>
      </div>

      <p class="max-w-prose text-xs text-warning-700">
        {{ t('payroll.eldp.noSendNotice') }}
      </p>

      <div v-if="prepared" class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-neutral-700">
        <p class="max-w-prose">{{ t('payroll.eldp.manualCompletionNotice') }}</p>
        <p v-if="downloadError" class="mt-2 text-danger-700" role="alert">{{ downloadError }}</p>
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="downloading"
          data-test="eldp-download"
          class="mt-3"
          @click="downloadControlXml"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ downloading ? t('payroll.eldp.downloading') : t('payroll.eldp.downloadControlXml') }}
        </button>
      </div>

      <section
        v-if="statement && manualCompletion"
        class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4"
        data-test="eldp-manual-completion"
      >
        <div>
          <h4 class="font-semibold text-neutral-900">
            {{ t('payroll.eldp.manual.title') }}
          </h4>
          <p class="mt-1 max-w-prose text-xs text-neutral-600">
            {{ t('payroll.eldp.manual.description') }}
          </p>
          <p class="mt-1 max-w-prose text-xs font-medium text-warning-700">
            {{ t('payroll.eldp.manual.controlXmlStaysPrepared') }}
          </p>
        </div>

        <div v-if="manualCompletion.evidence.length" class="space-y-2" data-test="eldp-manual-history">
          <article
            v-for="evidence in manualCompletion.evidence"
            :key="evidence.id"
            class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-700"
            :data-test="`eldp-evidence-${evidence.authority_status}`"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span
                class="rounded-full px-2 py-0.5 font-semibold"
                :class="evidence.authority_status === 'accepted'
                  ? 'bg-success-100 text-success-700'
                  : 'bg-warning-100 text-warning-700'"
              >
                {{ t(`payroll.eldp.manual.status.${evidence.authority_status}`) }}
              </span>
              <span>{{ evidence.confirmed_on }}</span>
            </div>
            <p class="mt-2 font-medium text-neutral-900">{{ evidence.authority_reference }}</p>
            <a
              :href="`/documents/${evidence.confirmation_document_id}`"
              class="mt-1 inline-flex text-primary-700 hover:underline"
            >
              {{ t('payroll.eldp.manual.documentLink', { id: evidence.confirmation_document_id }) }}
            </a>
            <p class="mt-1 break-all text-neutral-500">
              {{ t('payroll.eldp.manual.hashLine', {
                hash: evidence.confirmation_sha256,
                bytes: evidence.confirmation_byte_size,
              }) }}
            </p>
          </article>
        </div>

        <p
          v-if="hasAcceptedEvidence"
          class="rounded-md border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
          data-test="eldp-fulfilled"
        >
          {{ t('payroll.eldp.manual.fulfilled') }}
        </p>

        <div v-else-if="canWrite && canReadDocuments" class="space-y-3 border-t border-neutral-200 pt-4">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payroll.eldp.manual.resultLabel') }}
            <select
              v-model="authorityStatus"
              data-test="eldp-authority-status"
              class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900 sm:max-w-md"
            >
              <option value="submitted" :disabled="manualCompletion.evidence.some(item => item.authority_status === 'submitted')">
                {{ t('payroll.eldp.manual.status.submitted') }}
              </option>
              <option value="accepted">
                {{ t('payroll.eldp.manual.status.accepted') }}
              </option>
            </select>
          </label>
          <p
            class="max-w-prose rounded-md p-3 text-xs"
            :class="authorityStatus === 'accepted'
              ? 'bg-success-50 text-success-700'
              : 'bg-warning-50 text-warning-700'"
            data-test="eldp-authority-status-explanation"
          >
            {{ t(`payroll.eldp.manual.statusExplanation.${authorityStatus}`) }}
          </p>

          <div class="relative">
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.documentLabel') }}
              <span class="mt-1 flex flex-wrap gap-2">
                <input
                  v-model="confirmationDocumentQuery"
                  type="search"
                  :readonly="confirmationDocument !== null"
                  :placeholder="t('payroll.eldp.manual.documentPlaceholder')"
                  class="h-10 min-w-64 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  data-test="eldp-document-query"
                  @keyup.enter.prevent="searchConfirmationDocuments"
                >
                <button
                  v-if="confirmationDocument === null"
                  type="button"
                  :class="btnOutline('neutral')"
                  data-test="eldp-document-search"
                  @click="searchConfirmationDocuments"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.search" />
                  </svg>
                  {{ t('common.search') }}
                </button>
                <button v-else type="button" :class="btnOutline('neutral')" @click="clearConfirmationDocument">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.edit" />
                  </svg>
                  {{ t('common.edit') }}
                </button>
              </span>
            </label>
            <div
              v-if="confirmationDocuments.length"
              class="absolute z-20 mt-1 max-h-52 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"
            >
              <button
                v-for="document in confirmationDocuments"
                :key="document.id"
                type="button"
                class="cursor-pointer block w-full px-3 py-2 text-left text-sm text-neutral-900 hover:bg-neutral-100"
                data-test="eldp-document-option"
                @click="chooseConfirmationDocument(document)"
              >
                <span class="font-medium">{{ document.title }}</span>
                <span class="ml-2 text-xs text-neutral-500">{{ document.original_name }}</span>
              </button>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.eldp.manual.serverHashNotice') }}</p>
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.referenceLabel') }}
              <input
                v-model="authorityReference"
                maxlength="190"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"
                data-test="eldp-authority-reference"
              >
            </label>
            <label class="text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.confirmedOnLabel') }}
              <input
                v-model="confirmedOn"
                type="date"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"
                data-test="eldp-confirmed-on"
              >
            </label>
          </div>

          <p v-if="completionError" class="text-sm text-danger-700" role="alert" data-test="eldp-completion-error">
            {{ completionError }}
          </p>
          <p v-if="completionSuccess" class="text-sm text-success-700" role="status" data-test="eldp-completion-success">
            {{ completionSuccess }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button
              type="button"
              :class="btnFilled(authorityStatus === 'accepted' ? 'success' : 'primary')"
              :disabled="!canComplete"
              data-test="eldp-complete"
              @click="completeManually"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ completing ? t('common.saving') : t('payroll.eldp.manual.save') }}
            </button>
          </div>
        </div>

        <p v-else class="text-xs text-neutral-500">
          {{ t('payroll.eldp.manual.permissionRequired') }}
        </p>
      </section>

      <div class="flex justify-end border-t border-neutral-200 pt-4">
        <button
          type="button"
          :class="btnFilled('primary')"
          :disabled="!canPrepare"
          data-test="eldp-prepare"
          @click="prepare"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path :d="ICONS.clipboardCheck" />
          </svg>
          {{ t('payroll.eldp.prepare') }}
        </button>
      </div>
    </div>
  </div>
</template>

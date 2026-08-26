<script setup lang="ts">
/*
 * Evidenční list důchodového pojištění.
 *
 * Obrazovka končí přípravou. Žádné tlačítko tady neodesílá — podání se zastaví
 * ve stavu „připraveno" a odeslání spouští člověk ve Stavu odeslání. Je to
 * záměr, ne rozestavěnost: datová věta odesílaného ELDP není v připnuté
 * oficiální sadě, takže odeslat by stejně nešlo bez ověřeného schématu.
 */
import { computed, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEldpPrepared,
  type PayrollEldpStatement,
  type PayrollEmployment,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const preparing = ref(false)
const employments = ref<PayrollEmployment[]>([])
const personId = ref<number | null>(null)
const employmentId = ref<number | null>(null)
const year = ref<number>(new Date().getFullYear() - 1)
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
const excludedDaysConfirmed = ref(false)
const deductedDaysNone = ref(false)
const requestedByAuthority = ref(false)
const authorityRequestReceivedOn = ref('')
const note = ref('')
const statement = ref<PayrollEldpStatement | null>(null)
const prepared = ref<PayrollEldpPrepared | null>(null)
const blockers = ref<Array<{ code: string, message: string }>>([])
const error = ref('')
const success = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
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
 * Obě potvrzení musí padnout výslovně. Vyloučené doby mění osobní vyměřovací
 * základ a odečítané doby po dosažení důchodového věku modul neumí odvodit —
 * proto je nula podmíněná potvrzením, ne výpočtem.
 */
const canPrepare = computed(() =>
  canWrite.value
  && !preparing.value
  && employmentId.value !== null
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
  } catch {
    statement.value = null
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
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.eldp.legalBasis') }}
      </p>
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

      <!-- Jedno společné Uložit: příprava je jediná akce téhle obrazovky. -->
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

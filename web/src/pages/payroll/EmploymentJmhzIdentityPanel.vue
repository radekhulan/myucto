<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollJmhzIdentityStatus,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'
import { todayIso } from './employmentLifecycleUi'

const props = defineProps<{
  employmentId: number
  startDate: string | null
  endDate: string | null
  canWriteEmployment: boolean
  canWritePerson: boolean
}>()

const { t } = useI18n()
const today = todayIso()
const initialDate = computed(() => {
  if (props.startDate === null) return today
  if (today < props.startDate) return props.startDate
  if (props.endDate !== null && today > props.endDate) return props.endDate
  return today
})

const environment = ref<PayrollRegzelEnvironment>('test')
const onDate = ref(initialDate.value)
const validFrom = ref(props.startDate ?? initialDate.value)
const personIdentifier = ref('')
const employmentIdentifier = ref('')
const sourceReference = ref('')
const evidenceConfirmed = ref(false)
const status = ref<PayrollJmhzIdentityStatus | null>(null)
const loaded = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
let skipNextDateReload = false

const environmentOptions = computed(() => [
  { value: 'test' as const, label: t('payroll.regzel.environment.test') },
  { value: 'production' as const, label: t('payroll.regzel.environment.production') },
])
const complete = computed(() => status.value !== null
  && status.value.person_external_identifier !== null
  && status.value.employment_external_identifier !== null)
const canWrite = computed(() => props.canWriteEmployment && props.canWritePerson)
const canSave = computed(() => canWrite.value
  && !saving.value
  && evidenceConfirmed.value
  && validFrom.value !== ''
  && (
    (status.value?.person_external_identifier === null
      && personIdentifier.value.trim() !== '')
    || (status.value?.employment_external_identifier === null
      && employmentIdentifier.value.trim() !== '')
  ))

async function load(): Promise<void> {
  loaded.value = true
  status.value = null
  error.value = ''
  success.value = ''
  if (props.startDate === null) {
    error.value = t('payroll.people.jmhz_identity.start_date_missing')
    return
  }
  loading.value = true
  try {
    status.value = await payrollApi.jmhzIdentity(
      props.employmentId,
      environment.value,
      onDate.value,
    )
  } catch (cause) {
    error.value = apiErrorMessage(cause, t('payroll.people.jmhz_identity.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await payrollApi.saveJmhzIdentity(props.employmentId, {
      environment: environment.value,
      person_external_identifier: status.value?.person_external_identifier === null
        ? personIdentifier.value.trim()
        : null,
      employment_external_identifier: status.value?.employment_external_identifier === null
        ? employmentIdentifier.value.trim()
        : null,
      valid_from: validFrom.value,
      source_reference: sourceReference.value.trim() || null,
      evidence_confirmed: true,
    })
    if (onDate.value !== validFrom.value) {
      skipNextDateReload = true
      onDate.value = validFrom.value
    }
    personIdentifier.value = ''
    employmentIdentifier.value = ''
    sourceReference.value = ''
    evidenceConfirmed.value = false
    await load()
    success.value = t('payroll.people.jmhz_identity.saved')
  } catch (cause) {
    error.value = apiErrorMessage(cause, t('payroll.people.jmhz_identity.save_failed'))
  } finally {
    saving.value = false
  }
}

function openPanel(event: Event): void {
  if ((event.currentTarget as HTMLDetailsElement).open && !loaded.value) {
    void load()
  }
}

watch(environment, () => {
  personIdentifier.value = ''
  employmentIdentifier.value = ''
  sourceReference.value = ''
  validFrom.value = props.startDate ?? initialDate.value
  evidenceConfirmed.value = false
  if (loaded.value) void load()
})
watch(onDate, () => {
  evidenceConfirmed.value = false
  if (skipNextDateReload) {
    skipNextDateReload = false
    return
  }
  if (loaded.value) void load()
})
watch(() => [props.startDate, props.endDate], () => {
  onDate.value = initialDate.value
  validFrom.value = props.startDate ?? initialDate.value
  if (loaded.value) void load()
})
</script>

<template>
  <details
    class="group mt-4 overflow-hidden rounded-lg border border-neutral-200 bg-surface"
    data-test="jmhz-identity-panel"
    @toggle="openPanel"
  >
    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-3 py-3">
      <span>
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.jmhz_identity.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.jmhz_identity.summary') }}
        </span>
      </span>
      <span
        v-if="loaded && !loading"
        class="rounded-full px-2 py-0.5 text-xs font-medium"
        :class="complete ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'"
      >
        {{ complete
          ? t('payroll.people.jmhz_identity.complete')
          : t('payroll.people.jmhz_identity.incomplete') }}
      </span>
    </summary>

    <div class="space-y-4 border-t border-neutral-200 p-3 sm:p-4">
      <p class="text-sm text-neutral-600">
        {{ t('payroll.people.jmhz_identity.description') }}
      </p>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.jmhz_identity.environment') }}
          <SearchableSelect
            v-model="environment"
            :options="environmentOptions"
            :clearable="false"
            accent="payroll"
            class="mt-1"
            data-test="jmhz-identity-environment"
          />
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.jmhz_identity.on_date') }}
          <input
            v-model="onDate"
            type="date"
            :min="startDate ?? undefined"
            :max="endDate ?? undefined"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            data-test="jmhz-identity-on-date"
          >
        </label>
      </div>

      <p v-if="loading" class="text-sm text-neutral-500">
        {{ t('common.loading') }}
      </p>
      <p
        v-if="error"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-test="jmhz-identity-error"
      >
        {{ error }}
      </p>
      <p
        v-if="success"
        class="rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </p>

      <template v-if="status && !loading">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.people.jmhz_identity.person_identifier') }}
            </p>
            <p v-if="status.person_external_identifier" class="mt-1 font-mono text-sm text-neutral-900">
              {{ status.person_external_identifier.value_masked }}
            </p>
            <p v-else class="mt-1 text-sm font-medium text-warning-700">
              {{ t('payroll.people.jmhz_identity.missing') }}
            </p>
          </div>
          <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.people.jmhz_identity.employment_identifier') }}
            </p>
            <p v-if="status.employment_external_identifier" class="mt-1 font-mono text-sm text-neutral-900">
              {{ status.employment_external_identifier.value_masked }}
            </p>
            <p v-else class="mt-1 text-sm font-medium text-warning-700">
              {{ t('payroll.people.jmhz_identity.missing') }}
            </p>
          </div>
        </div>

        <form
          v-if="!complete"
          class="space-y-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
          data-test="jmhz-identity-form"
          @submit.prevent="save"
        >
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label
              v-if="status.person_external_identifier === null"
              class="text-xs text-neutral-600"
            >
              {{ t('payroll.people.jmhz_identity.person_identifier') }}
              <input
                v-model="personIdentifier"
                inputmode="numeric"
                autocomplete="off"
                maxlength="10"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 font-mono text-sm text-neutral-900"
                data-test="jmhz-person-identifier"
              >
              <span class="mt-1 block text-neutral-500">
                {{ t('payroll.people.jmhz_identity.person_hint') }}
              </span>
            </label>
            <label
              v-if="status.employment_external_identifier === null"
              class="text-xs text-neutral-600"
            >
              {{ t('payroll.people.jmhz_identity.employment_identifier') }}
              <input
                v-model="employmentIdentifier"
                inputmode="numeric"
                autocomplete="off"
                maxlength="22"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 font-mono text-sm text-neutral-900"
                data-test="jmhz-employment-identifier"
              >
              <span class="mt-1 block text-neutral-500">
                {{ t('payroll.people.jmhz_identity.employment_hint') }}
              </span>
            </label>
            <label class="text-xs text-neutral-600">
              {{ t('payroll.people.jmhz_identity.valid_from') }}
              <input
                v-model="validFrom"
                type="date"
                :min="startDate ?? undefined"
                :max="endDate ?? undefined"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
                data-test="jmhz-identity-valid-from"
              >
            </label>
            <label class="text-xs text-neutral-600">
              {{ t('payroll.people.jmhz_identity.source_reference') }}
              <input
                v-model="sourceReference"
                maxlength="500"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
                data-test="jmhz-identity-source"
              >
              <span class="mt-1 block text-neutral-500">
                {{ t('payroll.people.jmhz_identity.source_hint') }}
              </span>
            </label>
          </div>

          <label class="flex items-start gap-2 rounded-md border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800">
            <input
              v-model="evidenceConfirmed"
              type="checkbox"
              class="mt-0.5 rounded border-neutral-300 text-payroll-600"
              data-test="jmhz-identity-confirmed"
            >
            <span>{{ t('payroll.people.jmhz_identity.confirm') }}</span>
          </label>

          <div class="flex flex-wrap justify-end gap-2">
            <p v-if="!canWrite" class="mr-auto text-xs text-neutral-500">
              {{ t('payroll.people.jmhz_identity.permission_required') }}
            </p>
            <button
              type="submit"
              :class="btnFilled('success')"
              :disabled="!canSave"
              data-test="jmhz-identity-save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </form>
      </template>
    </div>
  </details>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollJmhzIdentityReveal,
  type PayrollJmhzIdentityStatus,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { todayIso } from './employmentLifecycleUi'
import { formatDate } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{
  employmentId: number
  startDate: string | null
  endDate: string | null
  canWriteEmployment: boolean
  canWritePerson: boolean
  // Oprávnění chodí propem stejně jako `canWrite*` — panel o store nic neví.
  canReadSensitive?: boolean
}>()

const { t } = useI18n()
const today = todayIso()
const initialDate = computed(() => {
  if (props.startDate === null) return today
  if (today < props.startDate) return props.startDate
  if (props.endDate !== null && today > props.endDate) return props.endDate
  return today
})

const environment = ref<PayrollRegzelEnvironment>('production')
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
const onDate = ref(initialDate.value)
const validFrom = ref(props.startDate ?? initialDate.value)
const personIdentifier = ref('')
const employmentIdentifier = ref('')
const sourceReference = ref('')
const evidenceConfirmed = ref(false)
const status = ref<PayrollJmhzIdentityStatus | null>(null)
/*
 * Obě čísla se opisují ručně z protokolu ČSSZ, takže překlep je běžný. Bez
 * režimu opravy se formulář po uložení schoval a chybné číslo se pak vezlo
 * v každém dalším hlášení — z aplikace z toho nevedla cesta ven.
 */
const correctingPerson = ref(false)
const correctingEmployment = ref(false)
/*
 * Maska odpoví jen na „je to vyplněné". Porovnat číslo s protokolem ČSSZ šlo
 * dřív jedině přes „Opravit", tedy za cenu přepsání správné hodnoty.
 *
 * Server vydává obě čísla najednou (jeden důvod, jeden zápis do auditu), ale
 * ukazuje se zvlášť to, co si účetní vyžádala — odkrýt druhé číslo jen proto,
 * že přišlo v téže odpovědi, není nic, o co požádala.
 */
const revealed = ref<PayrollJmhzIdentityReveal | null>(null)
const revealing = ref(false)
const showPerson = ref(false)
const showEmployment = ref(false)
const loaded = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
let skipNextDateReload = false

const complete = computed(() => status.value !== null
  && status.value.person_external_identifier !== null
  && status.value.employment_external_identifier !== null)
const canWrite = computed(() => props.canWriteEmployment && props.canWritePerson)
/*
 * Chybějící číslo neznamená vždy „opište ho": když přihláška teprve čeká ve
 * frontě nebo na protokol ČSSZ, číslo ještě nikdo nepřidělil a není co opsat.
 */
const pendingRegistration = computed(() => status.value?.pending_registration ?? null)
const pendingRegistrationText = computed(() => {
  const pending = pendingRegistration.value
  if (pending === null) return ''
  if (pending.state === 'queued') {
    return t('payroll.people.jmhz_identity.pending_queued', { id: pending.submission_id })
  }
  return pending.submitted_on === null
    ? t('payroll.people.jmhz_identity.pending_awaiting_undated', { id: pending.submission_id })
    : t('payroll.people.jmhz_identity.pending_awaiting', {
      id: pending.submission_id,
      date: formatDate(pending.submitted_on),
    })
})
const personEditable = computed(() => status.value !== null
  && (status.value.person_external_identifier === null || correctingPerson.value))
const employmentEditable = computed(() => status.value !== null
  && (status.value.employment_external_identifier === null
    || correctingEmployment.value))
const formOpen = computed(() => personEditable.value || employmentEditable.value)
const correcting = computed(() => correctingPerson.value || correctingEmployment.value)
/*
 * Stačí JEDEN z identifikátorů: OIČ osoby a ID PPV vztahu chodí z ČSSZ zvlášť
 * a účetní je typicky dostane po částech. Povinné je jen to, co uživatel právě
 * zadává, plus potvrzení podkladu — obojí drží ČSSZ, ne náš kód.
 */
const hasIdentifierToSave = computed(() =>
  (personEditable.value && personIdentifier.value.trim() !== '')
  || (employmentEditable.value && employmentIdentifier.value.trim() !== ''))

function startPersonCorrection(): void {
  correctingPerson.value = true
  personIdentifier.value = ''
  evidenceConfirmed.value = false
  success.value = ''
}

function startEmploymentCorrection(): void {
  correctingEmployment.value = true
  employmentIdentifier.value = ''
  evidenceConfirmed.value = false
  success.value = ''
}

/*
 * Odkrytá hodnota platí k prostředí a dni, ke kterému se načetl stav. Jakmile
 * se kterýkoli z nich pohne, ukazovalo by se číslo z jiného období.
 */
function forgetReveal(): void {
  revealed.value = null
  showPerson.value = false
  showEmployment.value = false
}

const personValue = computed(() => {
  const plain = revealed.value?.person_external_identifier
  const masked = status.value?.person_external_identifier
  if (!masked) return ''
  return showPerson.value && plain && plain.id === masked.id
    ? plain.value
    : masked.value_masked
})
const employmentValue = computed(() => {
  const plain = revealed.value?.employment_external_identifier
  const masked = status.value?.employment_external_identifier
  if (!masked) return ''
  return showEmployment.value && plain && plain.id === masked.id
    ? plain.value
    : masked.value_masked
})

async function toggleReveal(target: 'person' | 'employment'): Promise<void> {
  const shown = target === 'person' ? showPerson : showEmployment
  if (shown.value) {
    shown.value = false
    if (!showPerson.value && !showEmployment.value) revealed.value = null
    return
  }
  if (revealing.value) return
  revealing.value = true
  error.value = ''
  try {
    if (revealed.value === null) {
      revealed.value = await payrollApi.revealJmhzIdentity(
        props.employmentId,
        environment.value,
        onDate.value,
      )
    }
    shown.value = true
  } catch (cause) {
    error.value = apiErrorMessage(cause, t('payroll.people.jmhz_identity.reveal_failed'))
  } finally {
    revealing.value = false
  }
}

function cancelCorrection(): void {
  correctingPerson.value = false
  correctingEmployment.value = false
  personIdentifier.value = ''
  employmentIdentifier.value = ''
  sourceReference.value = ''
  evidenceConfirmed.value = false
  error.value = ''
}
const canSave = computed(() => canWrite.value
  && !saving.value
  && evidenceConfirmed.value
  && validFrom.value !== ''
  && hasIdentifierToSave.value)

/*
 * Zašedlé tlačítko bez důvodu je slepá ulička — uživatel nevidí, jestli mu
 * chybí hodnota, datum, nebo zaškrtnutí.
 */
const saveDisabledReason = computed(() => {
  if (!canWrite.value || saving.value) return ''
  if (!hasIdentifierToSave.value) {
    return t('payroll.people.jmhz_identity.identifier_required')
  }
  if (validFrom.value === '') return t('payroll.people.jmhz_identity.valid_from_required')
  if (!evidenceConfirmed.value) return t('payroll.people.jmhz_identity.confirm_required')
  return ''
})

async function load(): Promise<void> {
  loaded.value = true
  status.value = null
  forgetReveal()
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
    const wasCorrecting = correcting.value
    await payrollApi.saveJmhzIdentity(props.employmentId, {
      environment: environment.value,
      person_external_identifier: personEditable.value
        ? personIdentifier.value.trim() || null
        : null,
      employment_external_identifier: employmentEditable.value
        ? employmentIdentifier.value.trim() || null
        : null,
      valid_from: validFrom.value,
      source_reference: sourceReference.value.trim() || null,
      evidence_confirmed: true,
      replace_existing: wasCorrecting,
    })
    if (onDate.value !== validFrom.value) {
      skipNextDateReload = true
      onDate.value = validFrom.value
    }
    correctingPerson.value = false
    correctingEmployment.value = false
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
  cancelCorrection()
  forgetReveal()
  validFrom.value = props.startDate ?? initialDate.value
  if (loaded.value) void load()
})
watch(onDate, () => {
  evidenceConfirmed.value = false
  forgetReveal()
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
  <!--
    Kotva a `data-a1-field` níž jsou tu kvůli doskoku z nálezů: hláška „chybí
    OIČ / IK MPSV osoby" má mít tlačítko, které sem doveze, ne popis cesty.
    Povel `?person=&employment=&panel=jmhz_identity&field=…` zpracuje
    `PeopleList.focusPanel()` a `revealField()` blok cestou rozbalí — sbalený
    blok, na který nic neukazuje, je prakticky totéž jako žádný.
  -->
  <details
    class="group mt-4 scroll-mt-24 overflow-hidden rounded-lg border border-neutral-200 bg-surface"
    data-test="jmhz-identity-panel"
    data-panel-anchor="jmhz_identity"
    @toggle="openPanel"
  >
    <!--
      `list-none` schová vlastní trojúhelníček prohlížeče, takže blok vypadal
      jako obyčejný nadpis a nešlo poznat, že se dá rozkliknout. Šipku proto
      kreslíme sami a otáčíme ji podle stavu.
    -->
    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-3 hover:bg-neutral-50">
      <span class="flex items-center gap-2">
        <svg
          class="h-4 w-4 shrink-0 text-neutral-400 transition-transform group-open:rotate-180"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
        ><path :d="ICONS.chevron" /></svg>
        <span>
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.jmhz_identity.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.jmhz_identity.summary') }}
        </span>
        </span>
      </span>
      <span
        v-if="loaded && !loading"
        class="rounded-full px-2 py-0.5 text-xs font-medium"
        :class="complete ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'"
      >
        {{ complete
          ? t('payroll.people.jmhz_identity.complete')
          : pendingRegistration
            ? t(`payroll.people.jmhz_identity.pending_badge.${pendingRegistration.state}`)
            : t('payroll.people.jmhz_identity.incomplete') }}
      </span>
    </summary>

    <div class="space-y-4 border-t border-neutral-200 p-3 sm:p-4">
      <p class="text-sm text-neutral-600">
        {{ t('payroll.people.jmhz_identity.description') }}
      </p>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div v-if="submissionTestAllowed" class="text-xs text-neutral-600">
          {{ t('payroll.people.jmhz_identity.environment') }}
          <div class="mt-1">
            <EnvironmentSwitch
              v-model="environment"
              size="sm"
              :aria-label="t('payroll.people.jmhz_identity.environment')"
              data-test="jmhz-identity-environment"
            />
          </div>
        </div>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.jmhz_identity.on_date') }}
          <DateInput
            v-model="onDate"
            :min="startDate ?? undefined"
            :max="endDate ?? undefined"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            data-test="jmhz-identity-on-date" />
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
        <p
          v-if="pendingRegistration"
          class="rounded-lg border border-info-500/30 bg-info-50 p-3 text-sm text-info-700"
          role="status"
          data-test="jmhz-identity-pending-registration"
        >
          {{ pendingRegistrationText }}
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div
            class="scroll-mt-24 rounded-lg border border-neutral-200 bg-neutral-50 p-3"
            data-a1-field="jmhz.person_external_identifier"
          >
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.people.jmhz_identity.person_identifier') }}
            </p>
            <p v-if="status.person_external_identifier" class="mt-1 font-mono text-sm text-neutral-900">
              {{ personValue }}
            </p>
            <p v-else class="mt-1 text-sm font-medium text-warning-700">
              {{ t('payroll.people.jmhz_identity.missing') }}
            </p>
            <div v-if="status.person_external_identifier" class="mt-2 flex flex-wrap gap-2">
              <button
                v-if="canReadSensitive === true"
                type="button"
                :class="btnOutlineSm('neutral')"
                :disabled="revealing"
                data-test="jmhz-identity-reveal-person"
                @click="toggleReveal('person')"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.eye" />
                </svg>
                {{ showPerson
                  ? t('payroll.people.jmhz_identity.hide')
                  : t('payroll.people.jmhz_identity.reveal') }}
              </button>
              <button
                v-if="canWrite && !correctingPerson"
                type="button"
                :class="btnOutlineSm('neutral')"
                data-test="jmhz-identity-correct-person"
                @click="startPersonCorrection"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.people.jmhz_identity.correct') }}
              </button>
            </div>
          </div>
          <div
            class="scroll-mt-24 rounded-lg border border-neutral-200 bg-neutral-50 p-3"
            data-a1-field="jmhz.employment_external_identifier"
          >
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.people.jmhz_identity.employment_identifier') }}
            </p>
            <p v-if="status.employment_external_identifier" class="mt-1 font-mono text-sm text-neutral-900">
              {{ employmentValue }}
            </p>
            <p v-else class="mt-1 text-sm font-medium text-warning-700">
              {{ t('payroll.people.jmhz_identity.missing') }}
            </p>
            <div v-if="status.employment_external_identifier" class="mt-2 flex flex-wrap gap-2">
              <button
                v-if="canReadSensitive === true"
                type="button"
                :class="btnOutlineSm('neutral')"
                :disabled="revealing"
                data-test="jmhz-identity-reveal-employment"
                @click="toggleReveal('employment')"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.eye" />
                </svg>
                {{ showEmployment
                  ? t('payroll.people.jmhz_identity.hide')
                  : t('payroll.people.jmhz_identity.reveal') }}
              </button>
              <button
                v-if="canWrite && !correctingEmployment"
                type="button"
                :class="btnOutlineSm('neutral')"
                data-test="jmhz-identity-correct-employment"
                @click="startEmploymentCorrection"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.people.jmhz_identity.correct') }}
              </button>
            </div>
          </div>
        </div>

        <form
          v-if="formOpen"
          class="space-y-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
          data-test="jmhz-identity-form"
          @submit.prevent="save"
        >
          <p
            v-if="correcting"
            class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-xs text-warning-800"
            data-test="jmhz-identity-correction-hint"
          >
            {{ t('payroll.people.jmhz_identity.correction_hint') }}
          </p>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label
              v-if="personEditable"
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
              v-if="employmentEditable"
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
              <DateInput
                v-model="validFrom"
                :min="startDate ?? undefined"
                :max="endDate ?? undefined"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
                data-test="jmhz-identity-valid-from" />
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

          <div class="flex flex-wrap items-center justify-end gap-2">
            <p v-if="!canWrite" class="mr-auto text-xs text-neutral-500">
              {{ t('payroll.people.jmhz_identity.permission_required') }}
            </p>
            <p
              v-else-if="saveDisabledReason"
              class="mr-auto text-xs text-neutral-500"
              data-test="jmhz-identity-save-reason"
            >
              {{ saveDisabledReason }}
            </p>
            <button
              v-if="correcting"
              type="button"
              :class="btnOutlineSm('neutral')"
              data-test="jmhz-identity-cancel-correction"
              @click="cancelCorrection"
            >
              {{ t('common.cancel') }}
            </button>
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

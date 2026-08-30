<script setup lang="ts">
/*
 * Případy dávek nemocenského pojištění — NEMPRI a HZUPN.
 *
 * Obrazovka existuje proto, že § 97 zák. č. 187/2006 Sb. ukládá zaměstnavateli
 * DVĚ samostatné povinnosti s vlastními lhůtami:
 *
 *   odst. 1 a 2 — oznámit žádost o dávku a předat podklady pro výpočet;
 *                 u nemocenského „neprodleně po uplynutí prvních 14 dnů trvání
 *                 dočasné pracovní neschopnosti" (tiskopis NEMPRI),
 *   odst. 3     — neprodleně oznámit skutečnosti, které mohou mít vliv na
 *                 výplatu dávek, tedy i skončení neschopnosti (tiskopis HZUPN).
 *
 * Případ se proto eviduje SAMOSTATNĚ a žije i tehdy, když z něj nikdo podání
 * nepřipraví — lhůta běží tak jako tak a nesplnění je přestupek podle
 * § 130 odst. 1 písm. c) a d).
 *
 * Editor je vícesekční (případ, potvrzení zaměstnavatele, ukončení, dny práce)
 * a má proto JEDNO společné Uložit v liště dole. Tlačítko u každé sekce by
 * znamenalo, že se dá uložit půlka případu — a půlka případu je datová věta,
 * kterou ČSSZ odmítne.
 *
 * Odesílání tady není: podání končí ve stavu „připraveno" a odeslání spouští
 * člověk ve Stavu odeslání, stejně jako u registrace a ELDP.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollSicknessCasesApi,
  type PayrollSicknessBenefitKind,
  type PayrollSicknessCase,
  type PayrollSicknessCaseInput,
  type PayrollSicknessDocumentKind,
  type PayrollSicknessWorkInterval,
} from '@/api/payrollSicknessCases'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

const { t } = useI18n()
const auth = useAuthStore()

const loading = ref(true)
const creating = ref(false)
const saving = ref(false)
const busyId = ref<number | null>(null)
const items = ref<PayrollSicknessCase[]>([])
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})

const personId = ref<number | null>(null)
const employments = ref<PayrollEmployment[]>([])
const newEmploymentId = ref<number | null>(null)
const newBenefitKind = ref<PayrollSicknessBenefitKind>('NEM')
const newIncapacityFrom = ref('')

const editingId = ref<number | null>(null)
const draft = ref<PayrollSicknessCaseInput>({})
const draftWorkDays = ref<PayrollSicknessWorkInterval[]>([])
const previewXml = ref<{ id: number, document: string, xml: string } | null>(null)
const receiptDate = ref<Record<number, string>>({})
const receiptReason = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))

/**
 * Druhy dávky, u kterých aplikace datovou větu SESTAVÍ. `CtNem` i `CtVpm`
 * obsahují výhradně potvrzení zaměstnavatele; ostatní povinně nesou žádost
 * o dávku s údaji, které podává pojištěnec, ne zaměstnavatel.
 */
const SERIALIZABLE: PayrollSicknessBenefitKind[] = ['NEM', 'VPM']

const benefitKinds: PayrollSicknessBenefitKind[] = [
  'NEM', 'VPM', 'OPP', 'PPM', 'OSE', 'DLO',
]

const employmentOptions = computed(() =>
  employments.value.map(employment => ({
    value: employment.id,
    label: employment.end_date
      ? `${employment.code} (${employment.start_date ?? '?'} – ${employment.end_date})`
      : `${employment.code} (${employment.start_date ?? '?'})`,
  })))

const canCreate = computed(() =>
  canWrite.value
  && newEmploymentId.value !== null
  && newIncapacityFrom.value !== '')

const editing = computed(() =>
  items.value.find(item => item.id === editingId.value) ?? null)

/** Neplacené volno má prvek jen u nemocenského, ne u vyrovnávacího příspěvku. */
const draftHasUnpaidLeaveSection = computed(() =>
  editing.value?.benefit_kind === 'NEM')

function message(err: unknown): string {
  if (isAxiosError(err)) {
    const data = err.response?.data as { message?: string, error?: string } | undefined
    return data?.message || data?.error || t('payroll.sicknessCases.errors.generic')
  }
  return t('payroll.sicknessCases.errors.generic')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await payrollSicknessCasesApi.list(environment.value)
  } catch (err) {
    error.value = message(err)
  } finally {
    loading.value = false
  }
}

async function loadEmployments(id: number): Promise<void> {
  employments.value = []
  newEmploymentId.value = null
  try {
    const person = await payrollApi.person(id)
    employments.value = person.employments
    if (employments.value.length === 1) {
      newEmploymentId.value = employments.value[0].id
    }
  } catch (err) {
    error.value = message(err)
  }
}

async function create(): Promise<void> {
  if (!canCreate.value || newEmploymentId.value === null) return
  creating.value = true
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.create(environment.value, {
      employment_id: newEmploymentId.value,
      benefit_kind: newBenefitKind.value,
      incapacity_from: newIncapacityFrom.value,
    })
    newIncapacityFrom.value = ''
    success.value = t('payroll.sicknessCases.created')
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    creating.value = false
  }
}

function edit(item: PayrollSicknessCase): void {
  editingId.value = item.id
  draft.value = {
    ossz_code: item.ossz_code,
    decision_number: item.decision_number,
    correction: item.correction,
    foreign_case: item.foreign_case,
    incapacity_from: item.incapacity_from,
    incapacity_to: item.incapacity_to,
    issued_on: item.issued_on,
    payroll_payment_date: item.payroll_payment_date,
    worked_on_decisive_day: item.worked_on_decisive_day,
    hours_worked: item.hours_worked,
    daily_working_hours: item.daily_working_hours,
    receives_pension: item.receives_pension,
    pension_kind: item.pension_kind,
    is_student: item.is_student,
    first_employment_free_time: item.first_employment_free_time,
    unpaid_leave: item.unpaid_leave,
    unpaid_leave_from: item.unpaid_leave_from,
    unpaid_leave_to: item.unpaid_leave_to,
    transferred_other_work: item.transferred_other_work,
    transferred_on: item.transferred_on,
    enforcement: item.enforcement,
    insolvency: item.insolvency,
    returned_to_work: item.returned_to_work,
    return_reason: item.return_reason,
    returned_on: item.returned_on,
    hours_worked_last_day: item.hours_worked_last_day,
    shift_hours_last_day: item.shift_hours_last_day,
    additional_note: item.additional_note,
  }
  draftWorkDays.value = item.work_days.map(interval => ({ ...interval }))
}

function cancelEdit(): void {
  editingId.value = null
  draft.value = {}
  draftWorkDays.value = []
}

function addWorkInterval(): void {
  draftWorkDays.value.push({ from: '', to: '' })
}

function removeWorkInterval(index: number): void {
  draftWorkDays.value.splice(index, 1)
}

/** Jediné Uložit pro celý editor — sekce se neukládají po částech. */
async function save(): Promise<void> {
  const item = editing.value
  if (!item || !canWrite.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.update(
      environment.value,
      item.id,
      item.row_version,
      {
        ...draft.value,
        work_days: draftWorkDays.value.filter(
          interval => interval.from !== '' && interval.to !== '',
        ),
      },
    )
    success.value = t('payroll.sicknessCases.saved')
    cancelEdit()
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    saving.value = false
  }
}

async function preview(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  try {
    const result = await payrollSicknessCasesApi.preview(
      environment.value,
      item.id,
      document,
    )
    previewXml.value = { id: item.id, document, xml: result.xml }
  } catch (err) {
    previewXml.value = null
    error.value = message(err)
  } finally {
    busyId.value = null
  }
}

async function prepare(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.prepare(environment.value, item.id, document)
    success.value = t('payroll.sicknessCases.prepared')
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    busyId.value = null
  }
}

async function recordReceipt(
  item: PayrollSicknessCase,
  outcome: 'accepted' | 'rejected' | 'cancelled',
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.recordReceipt(environment.value, item.id, {
      outcome,
      accepted_on: receiptDate.value[item.id] || null,
      reason: receiptReason.value[item.id] || null,
    })
    success.value = t('payroll.sicknessCases.receiptRecorded')
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    busyId.value = null
  }
}

/**
 * Akce řádku. „Připravit NEMPRI" se u dávek, které aplikace sestavit neumí,
 * NESKRÝVÁ — zůstává zašedlá i s větou proč. Skrytá akce vypadá jako
 * neexistující povinnost, kdežto zašedlá říká, že povinnost je a splnit ji
 * musí člověk jinde.
 */
function actionsFor(item: PayrollSicknessCase): ActionItem[] {
  const serializable = SERIALIZABLE.includes(item.benefit_kind)
  const open = item.status !== 'accepted' && item.status !== 'cancelled'

  return [
    {
      key: 'edit',
      label: t('payroll.sicknessCases.actions.edit'),
      icon: 'edit',
      tier: 'primary',
      variant: 'primary',
      show: open && editingId.value !== item.id,
      disabled: !canWrite.value,
      disabledReason: t('payroll.sicknessCases.hints.readOnly'),
      run: () => edit(item),
    },
    {
      key: 'preview-nempri',
      label: t('payroll.sicknessCases.actions.previewNempri'),
      icon: 'eye',
      disabled: !serializable,
      disabledReason: t('payroll.sicknessCases.hints.benefitKindNotSerializable'),
      loading: busyId.value === item.id,
      run: () => void preview(item, 'nempri'),
    },
    {
      key: 'prepare-nempri',
      label: t('payroll.sicknessCases.actions.prepareNempri'),
      icon: 'check',
      disabled: !canWrite.value || !serializable || item.nempri_submission_id !== null,
      disabledReason: item.nempri_submission_id !== null
        ? t('payroll.sicknessCases.hints.alreadyPrepared')
        : t('payroll.sicknessCases.hints.benefitKindNotSerializable'),
      loading: busyId.value === item.id,
      run: () => void prepare(item, 'nempri'),
    },
    {
      key: 'preview-hzupn',
      label: t('payroll.sicknessCases.actions.previewHzupn'),
      icon: 'eye',
      disabled: item.incapacity_to === null,
      disabledReason: t('payroll.sicknessCases.hints.incapacityEndRequired'),
      loading: busyId.value === item.id,
      run: () => void preview(item, 'hzupn'),
    },
    {
      key: 'prepare-hzupn',
      label: t('payroll.sicknessCases.actions.prepareHzupn'),
      icon: 'check',
      disabled: !canWrite.value
        || item.incapacity_to === null
        || item.hzupn_submission_id !== null,
      disabledReason: item.hzupn_submission_id !== null
        ? t('payroll.sicknessCases.hints.alreadyPrepared')
        : t('payroll.sicknessCases.hints.incapacityEndRequired'),
      loading: busyId.value === item.id,
      run: () => void prepare(item, 'hzupn'),
    },
    {
      key: 'accept',
      label: t('payroll.sicknessCases.actions.recordAccepted'),
      tier: 'advanced',
      show: open,
      disabled: !canWrite.value || !receiptDate.value[item.id],
      disabledReason: t('payroll.sicknessCases.hints.receiptDateRequired'),
      run: () => void recordReceipt(item, 'accepted'),
    },
    {
      key: 'reject',
      label: t('payroll.sicknessCases.actions.recordRejected'),
      tier: 'advanced',
      variant: 'danger',
      show: open,
      disabled: !canWrite.value || !receiptReason.value[item.id],
      disabledReason: t('payroll.sicknessCases.hints.rejectionReasonRequired'),
      run: () => void recordReceipt(item, 'rejected'),
    },
  ]
}

const saveActions = computed<ActionItem[]>(() => [
  {
    key: 'save',
    label: t('payroll.sicknessCases.actions.save'),
    icon: 'check',
    tier: 'primary',
    variant: 'primary',
    loading: saving.value,
    disabled: !canWrite.value,
    disabledReason: t('payroll.sicknessCases.hints.readOnly'),
    run: () => void save(),
  },
  {
    key: 'cancel',
    label: t('payroll.sicknessCases.actions.cancel'),
    run: () => cancelEdit(),
  },
])

watch(personId, value => {
  if (value !== null) {
    void loadEmployments(value)
  } else {
    employments.value = []
    newEmploymentId.value = null
  }
})
watch(environment, () => {
  cancelEdit()
  void load()
})
onMounted(() => void load())
</script>

<template>
  <div class="space-y-4" data-test="sickness-cases-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4">
      <h3 class="mb-1 text-sm font-semibold text-neutral-900">
        {{ t('payroll.sicknessCases.title') }}
      </h3>
      <p class="mb-3 text-xs text-neutral-600">
        {{ t('payroll.sicknessCases.intro') }}
      </p>

      <div class="grid gap-3 md:grid-cols-4">
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.person') }}
          </span>
          <PayrollPersonSearchSelect
            v-model="personId"
            data-test="sickness-case-person"
            :label="t('payroll.sicknessCases.person')"
            :clearable="false"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.employment') }}
          </span>
          <SearchableSelect v-model="newEmploymentId" :options="employmentOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.benefitKind') }}
          </span>
          <select
            v-model="newBenefitKind"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm"
            data-test="sickness-case-benefit-kind"
          >
            <option v-for="kind in benefitKinds" :key="kind" :value="kind">
              {{ t(`payroll.sicknessCases.benefitKinds.${kind}`) }}
            </option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.incapacityFrom') }}
          </span>
          <input
            v-model="newIncapacityFrom"
            type="date"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm"
            data-test="sickness-case-incapacity-from"
          >
        </label>
      </div>

      <ActionBar
        class="mt-3"
        :actions="[{
          key: 'create',
          label: t('payroll.sicknessCases.actions.create'),
          icon: 'plus',
          tier: 'primary',
          variant: 'primary',
          loading: creating,
          disabled: !canCreate,
          disabledReason: t('payroll.sicknessCases.hints.createRequirements'),
          run: () => void create(),
        }]"
      />
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700" data-test="sickness-case-error">
      {{ error }}
    </p>
    <p v-if="success" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700" data-test="sickness-case-success">
      {{ success }}
    </p>

    <div v-if="loading" class="h-48 animate-pulse rounded-xl bg-neutral-100" />

    <div
      v-else-if="!items.length"
      class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-600"
    >
      {{ t('payroll.sicknessCases.empty') }}
    </div>

    <ul v-else class="space-y-3" data-test="sickness-cases-list">
      <li
        v-for="item in items"
        :key="item.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm"
        :data-test="`sickness-case-${item.id}`"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <span class="font-semibold text-neutral-900">{{ item.full_name }}</span>
            <span class="ml-2 text-neutral-600">
              {{ t(`payroll.sicknessCases.benefitKinds.${item.benefit_kind}`) }}
            </span>
          </div>
          <span class="text-xs text-neutral-500">
            {{ item.incapacity_from }} – {{ item.incapacity_to || '…' }}
            · {{ t(`payroll.sicknessCases.statuses.${item.status}`) }}
          </span>
        </div>

        <div
          v-if="editingId === item.id"
          class="mt-3 space-y-4"
          :data-test="`sickness-case-editor-${item.id}`"
        >
          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.case') }}
            </h4>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.osszCode') }}</span>
                <input v-model.number="draft.ossz_code" type="number" min="100" max="999" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.decisionNumber') }}</span>
                <input v-model="draft.decision_number" type="text" maxlength="18" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-decision-number">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.incapacityTo') }}</span>
                <input v-model="draft.incapacity_to" type="date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-incapacity-to">
              </label>
            </div>
          </section>

          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.employerConfirmation') }}
            </h4>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.worked_on_decisive_day" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.workedOnDecisiveDay') }}
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.dailyWorkingHours') }}</span>
                <input v-model="draft.daily_working_hours" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.hoursWorked') }}</span>
                <input v-model="draft.hours_worked" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.receives_pension" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.receivesPension') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.is_student" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.isStudent') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.enforcement" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.enforcement') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.insolvency" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.insolvency') }}
              </label>
              <label v-if="draftHasUnpaidLeaveSection" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.unpaid_leave" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-unpaid-leave">
                {{ t('payroll.sicknessCases.unpaidLeave') }}
              </label>
              <label v-if="draftHasUnpaidLeaveSection" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.unpaidLeaveFrom') }}</span>
                <input v-model="draft.unpaid_leave_from" type="date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
          </section>

          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.endOfIncapacity') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.hints.endOfIncapacity') }}
            </p>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.issuedOn') }}</span>
                <input v-model="draft.issued_on" type="date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-issued-on">
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.returned_to_work" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.returnedToWork') }}
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.returnedOn') }}</span>
                <input v-model="draft.returned_on" type="date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.hoursWorkedLastDay') }}</span>
                <input v-model="draft.hours_worked_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.shiftHoursLastDay') }}</span>
                <input v-model="draft.shift_hours_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
          </section>

          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.workDays') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.hints.workDays') }}
            </p>
            <div
              v-for="(interval, index) in draftWorkDays"
              :key="index"
              class="mb-2 flex flex-wrap items-end gap-2"
              :data-test="`sickness-case-work-day-${index}`"
            >
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.workedFrom') }}</span>
                <input v-model="interval.from" type="date" class="rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.workedTo') }}</span>
                <input v-model="interval.to" type="date" class="rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <button
                type="button"
                class="rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-700"
                :data-test="`sickness-case-work-day-remove-${index}`"
                @click="removeWorkInterval(index)"
              >
                {{ t('payroll.sicknessCases.actions.removeWorkInterval') }}
              </button>
            </div>
            <button
              type="button"
              class="rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-700"
              data-test="sickness-case-work-day-add"
              @click="addWorkInterval()"
            >
              {{ t('payroll.sicknessCases.actions.addWorkInterval') }}
            </button>
          </section>

          <!--
            Jedno společné Uložit pro celý editor. Tlačítko u každé sekce by
            znamenalo, že se dá uložit půlka případu.
          -->
          <div
            class="sticky bottom-0 -mx-4 mt-4 border-t border-neutral-200 bg-surface px-4 py-3"
            data-test="sickness-case-save-bar"
          >
            <ActionBar :actions="saveActions" />
          </div>
        </div>

        <div v-else class="mt-3 grid gap-3 md:grid-cols-2">
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.acceptedOn') }}</span>
            <input
              v-model="receiptDate[item.id]"
              type="date"
              class="w-full rounded-lg border border-neutral-300 p-2 text-sm"
              :data-test="`sickness-case-accepted-on-${item.id}`"
            >
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.rejectionReason') }}</span>
            <input
              v-model="receiptReason[item.id]"
              type="text"
              maxlength="190"
              class="w-full rounded-lg border border-neutral-300 p-2 text-sm"
              :data-test="`sickness-case-rejection-reason-${item.id}`"
            >
          </label>
        </div>

        <ActionBar class="mt-3" :actions="actionsFor(item)" />

        <pre
          v-if="previewXml && previewXml.id === item.id"
          class="mt-3 max-h-72 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs text-neutral-100"
          :data-test="`sickness-case-preview-${item.id}`"
        >{{ previewXml.xml }}</pre>
      </li>
    </ul>
  </div>
</template>

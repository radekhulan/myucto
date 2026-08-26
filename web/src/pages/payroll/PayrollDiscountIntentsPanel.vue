<script setup lang="ts">
/*
 * Záměr uplatňovat slevu na pojistném (OZUSPOJ).
 *
 * Obrazovka existuje proto, že sleva podle § 7a stojí na DVOU podáních a jen
 * jedno z nich je měsíční hlášení. Bez záměru doručeného ČSSZ (§ 7a odst. 5)
 * se sleva neuzná — a protože je kontrola 291 propustná, přijde to najevo až
 * z protokolu, kdy je pojistné odvedené ponížené a § 7c odst. 3 z rozdílu
 * dělá dluh.
 *
 * Panel proto nikdy netvrdí „hotovo" na základě toho, že se něco připravilo.
 * Sleva se ve výpočtu uplatní teprve u záměru ve stavu „přijato", kde je
 * zapsaný DEN DORUČENÍ z protokolu ČSSZ.
 *
 * Odesílání tady není: podání končí ve stavu „připraveno" a odeslání spouští
 * člověk ve Stavu odeslání — stejně jako u registrace a ELDP.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollDiscountIntentsApi,
  type PayrollDiscountIntent,
  type PayrollDiscountIntentSubmissionKind,
} from '@/api/payrollDiscountIntents'
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
const busyId = ref<number | null>(null)
const creating = ref(false)
const items = ref<PayrollDiscountIntent[]>([])
const employments = ref<PayrollEmployment[]>([])
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
const personId = ref<number | null>(null)
const employmentId = ref<number | null>(null)
const intentFrom = ref('')
const employeeInformedOn = ref('')
const acceptedOn = ref<Record<number, string>>({})
const endOn = ref<Record<number, string>>({})
const rejectionReason = ref<Record<number, string>>({})
const previewXml = ref<{ id: number, xml: string } | null>(null)
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
  {
    value: 'production' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.production'),
  },
  {
    value: 'test' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.test'),
  },
])
const canCreate = computed(() =>
  canWrite.value
  && !creating.value
  && employmentId.value !== null
  && intentFrom.value !== '')
/*
 * Přechodné pravidlo za 01–03/2026 se ukazuje jako samostatné varování nad
 * tabulkou, ne jako sloupec: kontroly 164, 290 a 333 ho u ČSSZ vyhodnotit
 * neumí (potřebují datum přijetí podání), takže jinak by uživatel nedostal
 * upozornění vůbec.
 */
const transitionalItems = computed(() =>
  items.value.filter(item => item.transitional_q1_2026))

function message(cause: unknown, fallback: string): string {
  if (isAxiosError(cause)) {
    const detail = cause.response?.data?.error?.message
    if (typeof detail === 'string' && detail !== '') {
      return detail
    }
  }
  return fallback
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await payrollDiscountIntentsApi.list(environment.value)
  } catch (cause) {
    error.value = message(cause, t('payroll.discountIntents.errors.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function loadEmployments(id: number): Promise<void> {
  employments.value = []
  employmentId.value = null
  try {
    const person = await payrollApi.person(id)
    employments.value = person.employments
    if (employments.value.length === 1) {
      employmentId.value = employments.value[0].id
    }
  } catch (cause) {
    error.value = message(cause, t('payroll.discountIntents.errors.loadFailed'))
  }
}

async function run(action: () => Promise<unknown>, ok: string): Promise<void> {
  error.value = ''
  success.value = ''
  try {
    await action()
    success.value = ok
    await load()
  } catch (cause) {
    error.value = message(cause, t('payroll.discountIntents.errors.actionFailed'))
  }
}

async function create(): Promise<void> {
  if (employmentId.value === null) {
    return
  }
  creating.value = true
  await run(
    () => payrollDiscountIntentsApi.create(environment.value, {
      employment_id: employmentId.value as number,
      intent_from: intentFrom.value,
      employee_informed_on: employeeInformedOn.value || null,
    }),
    t('payroll.discountIntents.created'),
  )
  creating.value = false
}

async function prepare(
  item: PayrollDiscountIntent,
  kind: PayrollDiscountIntentSubmissionKind,
): Promise<void> {
  busyId.value = item.id
  await run(
    () => payrollDiscountIntentsApi.prepare(item.id, environment.value, kind),
    t('payroll.discountIntents.prepared'),
  )
  busyId.value = null
}

async function preview(
  item: PayrollDiscountIntent,
  kind: PayrollDiscountIntentSubmissionKind,
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  try {
    const response = await payrollDiscountIntentsApi.preview(
      item.id,
      environment.value,
      kind,
    )
    previewXml.value = { id: item.id, xml: response.xml }
  } catch (cause) {
    error.value = message(cause, t('payroll.discountIntents.errors.actionFailed'))
  } finally {
    busyId.value = null
  }
}

async function accept(item: PayrollDiscountIntent): Promise<void> {
  busyId.value = item.id
  await run(
    () => payrollDiscountIntentsApi.recordReceipt(item.id, environment.value, {
      outcome: 'accepted',
      accepted_on: acceptedOn.value[item.id] ?? '',
    }),
    t('payroll.discountIntents.accepted'),
  )
  busyId.value = null
}

async function reject(item: PayrollDiscountIntent): Promise<void> {
  busyId.value = item.id
  await run(
    () => payrollDiscountIntentsApi.recordReceipt(item.id, environment.value, {
      outcome: 'rejected',
      reason: rejectionReason.value[item.id] ?? '',
    }),
    t('payroll.discountIntents.rejected'),
  )
  busyId.value = null
}

async function requestEnd(item: PayrollDiscountIntent): Promise<void> {
  busyId.value = item.id
  await run(
    () => payrollDiscountIntentsApi.requestEnd(
      item.id,
      environment.value,
      endOn.value[item.id] ?? '',
    ),
    t('payroll.discountIntents.endRequested'),
  )
  busyId.value = null
}

async function confirmEnd(item: PayrollDiscountIntent): Promise<void> {
  busyId.value = item.id
  await run(
    () => payrollDiscountIntentsApi.recordReceipt(item.id, environment.value, {
      outcome: 'ended',
      accepted_on: acceptedOn.value[item.id] ?? '',
    }),
    t('payroll.discountIntents.ended'),
  )
  busyId.value = null
}

function actionsFor(item: PayrollDiscountIntent): ActionItem[] {
  const busy = busyId.value === item.id
  return [
    {
      key: 'preview',
      label: t('payroll.discountIntents.actions.preview'),
      icon: 'eye',
      tier: 'secondary',
      variant: 'neutral',
      loading: busy,
      show: item.status === 'draft' || item.status === 'submitted',
      run: () => void preview(item, 'start'),
    },
    {
      key: 'prepare',
      label: t('payroll.discountIntents.actions.prepare'),
      icon: 'check',
      tier: 'primary',
      variant: 'primary',
      loading: busy,
      disabled: !canWrite,
      show: item.status === 'draft',
      run: () => void prepare(item, 'start'),
    },
    {
      key: 'accept',
      label: t('payroll.discountIntents.actions.accept'),
      icon: 'check',
      tier: 'primary',
      variant: 'success',
      loading: busy,
      disabled: !canWrite || !(acceptedOn.value[item.id] ?? ''),
      disabledReason: t('payroll.discountIntents.hints.acceptedOnRequired'),
      show: item.status === 'submitted',
      run: () => void accept(item),
    },
    {
      key: 'reject',
      label: t('payroll.discountIntents.actions.reject'),
      icon: 'x',
      tier: 'secondary',
      variant: 'danger',
      loading: busy,
      disabled: !canWrite || !(rejectionReason.value[item.id] ?? ''),
      disabledReason: t('payroll.discountIntents.hints.rejectionReasonRequired'),
      show: item.status === 'submitted',
      run: () => void reject(item),
    },
    {
      key: 'end',
      label: t('payroll.discountIntents.actions.end'),
      icon: 'calendar',
      tier: 'secondary',
      variant: 'neutral',
      loading: busy,
      disabled: !canWrite || !(endOn.value[item.id] ?? ''),
      disabledReason: t('payroll.discountIntents.hints.endOnRequired'),
      show: item.status === 'accepted' && item.intent_to === null,
      run: () => void requestEnd(item),
    },
    {
      key: 'prepare-end',
      label: t('payroll.discountIntents.actions.prepareEnd'),
      icon: 'check',
      tier: 'primary',
      variant: 'primary',
      loading: busy,
      disabled: !canWrite,
      show: item.status === 'accepted' && item.intent_to !== null,
      run: () => void prepare(item, 'end'),
    },
    {
      key: 'confirm-end',
      label: t('payroll.discountIntents.actions.confirmEnd'),
      icon: 'check',
      tier: 'secondary',
      variant: 'success',
      loading: busy,
      disabled: !canWrite || !(acceptedOn.value[item.id] ?? ''),
      disabledReason: t('payroll.discountIntents.hints.acceptedOnRequired'),
      show: item.status === 'accepted' && item.intent_to !== null,
      run: () => void confirmEnd(item),
    },
    {
      key: 'cancel',
      label: t('payroll.discountIntents.actions.cancel'),
      icon: 'trash',
      tier: 'overflow',
      variant: 'danger',
      loading: busy,
      disabled: !canWrite,
      show: item.status === 'submitted' || item.status === 'accepted',
      run: () => void prepare(item, 'cancellation'),
    },
  ]
}

watch(personId, value => {
  if (value !== null) {
    void loadEmployments(value)
  } else {
    employments.value = []
    employmentId.value = null
  }
})
watch(environment, () => void load())
onMounted(async () => {
  await load()
})
</script>

<template>
  <div class="space-y-4" data-test="discount-intents-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-700">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.discountIntents.title') }}
      </h3>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.discountIntents.intro') }}
      </p>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.discountIntents.legalBasis') }}
      </p>
    </div>

    <div
      v-if="error"
      data-test="discount-intents-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      {{ error }}
    </div>

    <div
      v-if="success"
      data-test="discount-intents-success"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      role="status"
    >
      {{ success }}
    </div>

    <div
      v-if="transitionalItems.length"
      data-test="discount-intents-transitional"
      class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-800"
      role="alert"
    >
      {{ t('payroll.discountIntents.transitionalWarning') }}
      <ul class="mt-2 list-disc space-y-1 pl-5">
        <li v-for="item in transitionalItems" :key="item.id">
          {{ item.employee_name }} — {{ item.intent_from }}
        </li>
      </ul>
    </div>

    <div class="space-y-4 rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.discountIntents.person') }}
          </span>
          <PayrollPersonSearchSelect
            v-model="personId"
            data-test="discount-intent-person"
            :label="t('payroll.discountIntents.person')"
            :clearable="false"
          />
        </div>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.discountIntents.employment') }}
          </span>
          <SearchableSelect v-model="employmentId" :options="employmentOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.discountIntents.intentFrom') }}
          </span>
          <input
            v-model="intentFrom"
            type="date"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
            data-test="discount-intent-from"
          >
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.discountIntents.employeeInformedOn') }}
          </span>
          <input
            v-model="employeeInformedOn"
            type="date"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
            data-test="discount-intent-informed-on"
          >
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.discountIntents.employeeInformedHint') }}
          </span>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.regzel.environment.label') }}
          </span>
          <SearchableSelect v-model="environment" :options="environmentOptions" />
        </label>
      </div>

      <ActionBar
        :actions="[{
          key: 'create',
          label: t('payroll.discountIntents.actions.create'),
          icon: 'plus',
          tier: 'primary',
          variant: 'primary',
          loading: creating,
          disabled: !canCreate,
          disabledReason: t('payroll.discountIntents.hints.createRequirements'),
          run: () => void create(),
        }]"
      />
    </div>

    <div v-if="loading" class="h-48 animate-pulse rounded-xl bg-neutral-100" />

    <div v-else-if="!items.length" class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-600">
      {{ t('payroll.discountIntents.empty') }}
    </div>

    <ul v-else class="space-y-3" data-test="discount-intents-list">
      <li
        v-for="item in items"
        :key="item.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm"
        :data-test="`discount-intent-${item.id}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-medium text-neutral-900">{{ item.employee_name }}</p>
            <p class="text-xs text-neutral-500">
              {{ t('payroll.discountIntents.reasons.' + item.discount_reason) }}
            </p>
          </div>
          <span
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            :class="item.evidences_discount
              ? 'bg-success-50 text-success-700'
              : 'bg-neutral-100 text-neutral-600'"
            :data-test="`discount-intent-status-${item.id}`"
          >
            {{ t('payroll.discountIntents.statuses.' + item.status) }}
          </span>
        </div>

        <dl class="mt-3 grid gap-2 sm:grid-cols-4">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.discountIntents.intentFrom') }}</dt>
            <dd class="font-medium">{{ item.intent_from }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.discountIntents.intentTo') }}</dt>
            <dd class="font-medium">{{ item.intent_to ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.discountIntents.acceptedOn') }}</dt>
            <dd class="font-medium">{{ item.accepted_on ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.discountIntents.dueOn') }}</dt>
            <dd class="font-medium">{{ item.notification_due_on }}</dd>
          </div>
        </dl>

        <p
          v-if="!item.evidences_discount"
          class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-800"
          :data-test="`discount-intent-not-evidenced-${item.id}`"
        >
          {{ t('payroll.discountIntents.notEvidenced') }}
        </p>
        <p
          v-if="item.rejection_reason"
          class="mt-2 rounded-lg bg-danger-50 p-2 text-xs text-danger-700"
        >
          {{ item.rejection_reason }}
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <label v-if="item.status === 'submitted' || item.status === 'accepted'" class="block text-xs">
            <span class="mb-1 block font-medium text-neutral-700">
              {{ t('payroll.discountIntents.acceptedOnInput') }}
            </span>
            <input
              v-model="acceptedOn[item.id]"
              type="date"
              class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
              :data-test="`discount-intent-accepted-on-${item.id}`"
            >
          </label>
          <label v-if="item.status === 'submitted'" class="block text-xs">
            <span class="mb-1 block font-medium text-neutral-700">
              {{ t('payroll.discountIntents.rejectionReason') }}
            </span>
            <input
              v-model="rejectionReason[item.id]"
              type="text"
              maxlength="190"
              class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
              :data-test="`discount-intent-reason-${item.id}`"
            >
          </label>
          <label v-if="item.status === 'accepted' && item.intent_to === null" class="block text-xs">
            <span class="mb-1 block font-medium text-neutral-700">
              {{ t('payroll.discountIntents.endOnInput') }}
            </span>
            <input
              v-model="endOn[item.id]"
              type="date"
              class="w-full rounded-lg border border-neutral-300 p-2 text-sm"
              :data-test="`discount-intent-end-on-${item.id}`"
            >
          </label>
        </div>

        <ActionBar class="mt-3" :actions="actionsFor(item)" />

        <pre
          v-if="previewXml && previewXml.id === item.id"
          class="mt-3 max-h-72 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs text-neutral-100"
          :data-test="`discount-intent-preview-${item.id}`"
        >{{ previewXml.xml }}</pre>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
/*
 * Přihlášky zaměstnanců k podání (REGZEC A1) hromadně.
 *
 * Seznam vztahů, které ve zvoleném období trvají nebo začnou a u ČSSZ ještě
 * přihlášené nejsou. U každého ukáže, jestli je profil registrace úplný, co
 * v něm chybí a do kdy se má přihláška podat. Vybraným s úplným profilem
 * připraví přihlášku stejnou cestou jako karta vztahu; odesílá se jako
 * vždy až ve frontě K odeslání.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollRegistrationA1BatchResult,
  type PayrollRegistrationA1Candidate,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

type BatchResult = PayrollRegistrationA1BatchResult['results'][number]

const PROBLEMS_SHOWN = 3
const SKIP_CODES = ['id_ppv', 'live_registration', 'predecessor', 'profile_incomplete', 'not_found']

const { t } = useI18n()
const auth = useAuthStore()
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})

const loading = ref(true)
const busy = ref(false)
const items = ref<PayrollRegistrationA1Candidate[]>([])
const from = ref('')
const to = ref('')
const selected = ref<number[]>([])
const results = ref<Array<BatchResult & { employee_id: number, employee_name: string }>>([])
const error = ref('')
const success = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
/*
 * Vztah, jehož okno přihlášky se ještě neotevřelo, se k přípravě nenabízí:
 * server by ho odmítl a řádek říká, od kdy půjde podat.
 */
function preparable(item: PayrollRegistrationA1Candidate): boolean {
  return item.complete && item.deadline?.too_early !== true
}

const selectable = computed(() => items.value.filter(preparable))
const allSelected = computed(
  () => selectable.value.length > 0
    && selectable.value.every(item => selected.value.includes(item.employment_id)),
)
const canSubmit = computed(() => canWrite.value && !busy.value && selected.value.length > 0)

function cardTarget(item: { employee_id: number, employment_id: number }) {
  return {
    name: 'payroll-people',
    query: {
      person: String(item.employee_id),
      employment: String(item.employment_id),
      panel: 'registration',
    },
  }
}

const queueTarget = { name: 'payroll-submissions-tab', params: { tab: 'queue' } }

function profileBadge(item: PayrollRegistrationA1Candidate): string {
  if (item.profile_status === 'verified') return 'bg-success-50 text-success-700 ring-success-500/30'
  if (item.profile_status === 'draft') return 'bg-warning-50 text-warning-800 ring-warning-500/30'
  return 'bg-danger-50 text-danger-700 ring-danger-500/30'
}

function deadlineText(item: PayrollRegistrationA1Candidate): string {
  if (item.deadline === null) return t('payroll.registrationA1.deadline.unknown')
  if (!item.deadline.derived) return t('payroll.registrationA1.deadline.not_derived')
  const date = formatDate(item.deadline.due_on)
  if (item.deadline.too_early === true) {
    return t('payroll.registrationA1.deadline.from', {
      from: formatDate(item.deadline.earliest_registration_on),
      date,
    })
  }
  return item.deadline.overdue
    ? t('payroll.registrationA1.deadline.overdue', { date })
    : t('payroll.registrationA1.deadline.due', { date })
}

function resultText(result: BatchResult): string {
  if (result.status === 'prepared') {
    return t(
      result.created ? 'payroll.registrationA1.result.prepared' : 'payroll.registrationA1.result.existing',
      { id: result.submission_id ?? '' },
    )
  }
  if (result.code !== null && SKIP_CODES.includes(result.code)) {
    return t(`payroll.registrationA1.skip.${result.code}`)
  }
  return result.message ?? ''
}

function toggle(id: number): void {
  selected.value = selected.value.includes(id)
    ? selected.value.filter(value => value !== id)
    : [...selected.value, id]
}

function toggleAll(): void {
  selected.value = allSelected.value
    ? []
    : selectable.value.map(item => item.employment_id)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const data = await payrollApi.registrationA1Candidates(environment.value, {
      ...(from.value !== '' ? { from: from.value } : {}),
      ...(to.value !== '' ? { to: to.value } : {}),
    })
    items.value = data.items
    from.value = data.from
    to.value = data.to
    selected.value = selected.value.filter(id =>
      data.items.some(item => item.employment_id === id && preparable(item)))
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.registrationA1.load_failed'))
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const known = new Map(items.value.map(item => [item.employment_id, item]))
    const response = await payrollApi.prepareRegistrationA1Batch({
      environment: environment.value,
      employment_ids: [...selected.value],
    })
    results.value = response.results.map(result => ({
      ...result,
      employee_id: known.get(result.employment_id)?.employee_id ?? 0,
      employee_name: known.get(result.employment_id)?.employee_name ?? String(result.employment_id),
    }))
    const prepared = response.results.filter(result => result.status === 'prepared').length
    success.value = t('payroll.registrationA1.done', {
      prepared,
      skipped: response.results.length - prepared,
    })
    selected.value = []
    await load()
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.registrationA1.submit_failed'))
  } finally {
    busy.value = false
  }
}

watch(environment, () => {
  results.value = []
  selected.value = []
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-4" data-test="registration-a1-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-700">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.registrationA1.title') }}
      </h3>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.registrationA1.intro') }}
      </p>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.registrationA1.legal') }}
      </p>
    </div>

    <div
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="registration-a1-error"
    >
      {{ error }}
    </div>
    <div
      v-if="success"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      role="status"
      data-test="registration-a1-success"
    >
      <p>
        {{ success }}
        <RouterLink
          :to="queueTarget"
          class="ml-1 font-medium underline underline-offset-2"
          data-test="registration-a1-queue-link"
        >
          {{ t('payroll.registrationA1.open_queue') }}
        </RouterLink>
      </p>
      <ul class="mt-2 space-y-1 text-xs" data-test="registration-a1-results">
        <li
          v-for="result in results"
          :key="result.employment_id"
          :class="result.status === 'prepared' ? 'text-success-700' : 'text-danger-700'"
          :data-test="`registration-a1-result-${result.employment_id}`"
        >
          <span class="font-medium">{{ result.employee_name }}:</span>
          {{ resultText(result) }}
          <RouterLink
            v-if="result.status === 'prepared' || result.code === 'live_registration'"
            :to="queueTarget"
            class="ml-1 font-medium underline underline-offset-2"
          >
            {{ t('payroll.registrationA1.result.open') }}
          </RouterLink>
          <RouterLink
            v-else-if="result.employee_id > 0"
            :to="cardTarget(result)"
            class="ml-1 font-medium underline underline-offset-2"
          >
            {{ t('payroll.registrationA1.open_profile') }}
          </RouterLink>
        </li>
      </ul>
    </div>

    <div class="rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-xs font-medium text-neutral-700">
          {{ t('payroll.registrationA1.from') }}
          <DateInput
            v-model="from"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:w-44"
            data-test="registration-a1-from"
          />
        </label>
        <label class="text-xs font-medium text-neutral-700">
          {{ t('payroll.registrationA1.to') }}
          <DateInput
            v-model="to"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:w-44"
            data-test="registration-a1-to"
          />
        </label>
        <button
          type="button"
          :class="btnOutline('neutral')"
          class="whitespace-nowrap"
          :disabled="loading || busy"
          data-test="registration-a1-reload"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.registrationA1.reload') }}
        </button>
        <button
          type="button"
          :class="btnFilled('primary')"
          class="whitespace-nowrap"
          :disabled="!canSubmit"
          data-test="registration-a1-submit"
          @click="submit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t('payroll.registrationA1.submit', { count: selected.length }) }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    <div
      v-else-if="items.length === 0"
      class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-600"
      data-test="registration-a1-empty"
    >
      {{ t('payroll.registrationA1.empty') }}
    </div>
    <div v-else class="overflow-x-auto rounded-xl border border-neutral-200 bg-surface">
      <table class="min-w-full divide-y divide-neutral-200 text-sm" data-test="registration-a1-table">
        <thead class="bg-neutral-50 text-left text-xs font-medium text-neutral-600">
          <tr>
            <th class="px-3 py-2">
              <input
                type="checkbox"
                :checked="allSelected"
                :disabled="selectable.length === 0 || !canWrite"
                :aria-label="t('payroll.registrationA1.select_all')"
                data-test="registration-a1-select-all"
                @change="toggleAll"
              >
            </th>
            <th class="px-3 py-2">{{ t('payroll.registrationA1.col.person') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationA1.col.period') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationA1.col.profile') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationA1.col.deadline') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="item in items"
            :key="item.employment_id"
            :data-test="`registration-a1-row-${item.employment_id}`"
          >
            <td class="px-3 py-2 align-top">
              <input
                type="checkbox"
                :checked="selected.includes(item.employment_id)"
                :disabled="!preparable(item) || !canWrite"
                :aria-label="t('payroll.registrationA1.select_one', { name: item.employee_name })"
                :data-test="`registration-a1-select-${item.employment_id}`"
                @change="toggle(item.employment_id)"
              >
            </td>
            <td class="px-3 py-2 align-top">
              <p class="font-medium text-neutral-900">{{ item.employee_name }}</p>
              <p class="text-xs text-neutral-500">{{ item.code }}</p>
              <p
                v-for="warning in item.warnings"
                :key="warning.code"
                class="mt-1 max-w-sm text-xs text-warning-800"
                :data-test="`registration-a1-warning-${item.employment_id}`"
              >
                {{ t(`payroll.registrationA1.warning.${warning.code}`, { code: warning.employment_code }) }}
              </p>
            </td>
            <td class="whitespace-nowrap px-3 py-2 align-top text-xs text-neutral-700">
              {{ item.start_date ? formatDate(item.start_date) : '?' }}
              –
              {{ item.end_date ? formatDate(item.end_date) : t('payroll.registrationA1.open_ended') }}
            </td>
            <td class="px-3 py-2 align-top">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="profileBadge(item)"
                :data-test="`registration-a1-profile-${item.employment_id}`"
              >
                {{ t(`payroll.registrationA1.profile.${item.profile_status}`) }}
              </span>
              <ul
                v-if="item.problems.length > 0"
                class="mt-1 max-w-md list-disc space-y-0.5 pl-4 text-xs text-neutral-600"
                :data-test="`registration-a1-problems-${item.employment_id}`"
              >
                <li v-for="(problem, index) in item.problems.slice(0, PROBLEMS_SHOWN)" :key="index">
                  {{ problem.message }}
                </li>
                <li v-if="item.problems.length > PROBLEMS_SHOWN" class="list-none text-neutral-500">
                  {{ t('payroll.registrationA1.more_problems', { count: item.problems.length - PROBLEMS_SHOWN }) }}
                </li>
              </ul>
              <RouterLink
                :to="cardTarget(item)"
                :class="btnOutline(item.complete ? 'neutral' : 'warning')"
                class="mt-1 whitespace-nowrap"
                :data-test="`registration-a1-open-${item.employment_id}`"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.eye" />
                </svg>
                {{ t('payroll.registrationA1.open_profile') }}
              </RouterLink>
            </td>
            <td class="px-3 py-2 align-top text-xs">
              <p
                :class="item.deadline?.overdue ? 'font-medium text-danger-700' : 'text-neutral-700'"
                :data-test="`registration-a1-deadline-${item.employment_id}`"
              >
                {{ deadlineText(item) }}
              </p>
              <p v-if="item.after_pre_registration" class="mt-1 text-neutral-500">
                {{ t('payroll.registrationA1.deadline.after_p1') }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

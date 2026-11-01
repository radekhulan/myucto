<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { payrollApi, type PayrollPersonOption } from '@/api/payroll'
import {
  payrollTakeoverWagesApi,
  type PayrollTakeoverManualEntry,
  type PayrollTakeoverManualRow,
} from '@/api/payrollMigrationReconciliation'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import {
  crownsToMinor,
  daysToHundredths,
  hoursToMinutes,
  hundredthsToDays,
  minorToCrowns,
  minutesToHours,
  wholeDays,
} from './takeoverAmounts'
import { monthRanges } from './takeoverMonths'

/**
 * Ruční zadání převzatých mezd — pro firmu, jejíž předchozí program nevydá
 * export ani hlášení (nebo vedla mzdy na papíře).
 *
 * Jeden měsíc se zadá jednou, po pracovních vztazích, v korunách, a server z něj
 * naplní obě vrstvy převzatého měsíce: počáteční stav (roční zúčtování,
 * vyúčtování daně, potvrzení o příjmech) i převzatou mzdu (evidenční list
 * důchodového pojištění). Zaměstnanec se vybírá jménem, ne interním číslem.
 */
const props = defineProps<{ year: number; canWrite: boolean }>()
const emit = defineEmits<{ saved: [] }>()

const { t } = useI18n()
const toast = useToast()
/*
 * Proklik z kontroly převzetí nese `employee` v adrese, aby se formulář rovnou
 * otevřel u toho, u koho něco chybí. Mimo router (testy) se to přeskočí.
 */
const route = useRoute() as ReturnType<typeof useRoute> | undefined

const MONEY = [
  'gross_minor', 'net_minor', 'deductions_minor', 'net_payable_minor',
  'social_base_minor', 'health_base_minor', 'employee_social_minor', 'employee_health_minor',
  'employer_social_minor', 'employer_health_minor', 'health_minimum_top_up_minor',
  'advance_base_minor', 'advance_tax_minor', 'withholding_base_minor', 'withholding_tax_minor',
  'applied_credits_minor', 'applied_child_credit_minor', 'tax_bonus_minor',
] as const
/**
 * Čerpání ročních košů benefitů. Nejsou to příjmy měsíce, takže nerozhodují
 * o tom, jestli je měsíc prázdný.
 */
const BENEFITS = ['old_age_savings_minor', 'non_cash_health_benefit_minor', 'non_cash_leisure_benefit_minor'] as const
const AMOUNTS = [...MONEY, ...BENEFITS] as const
type MoneyField = typeof AMOUNTS[number]
type IncomeField = typeof MONEY[number]

/** Skupiny nad hlavičkou, ať se základ SP a ZP nepletou se základem daně. */
const GROUPS: { key: string; fields: MoneyField[] }[] = [
  { key: 'wage', fields: ['gross_minor', 'net_minor', 'deductions_minor', 'net_payable_minor'] },
  {
    key: 'insurance',
    fields: [
      'social_base_minor', 'health_base_minor', 'employee_social_minor', 'employee_health_minor',
      'employer_social_minor', 'employer_health_minor', 'health_minimum_top_up_minor',
    ],
  },
  {
    key: 'tax',
    fields: [
      'advance_base_minor', 'advance_tax_minor', 'withholding_base_minor', 'withholding_tax_minor',
      'applied_credits_minor', 'applied_child_credit_minor', 'tax_bonus_minor',
    ],
  },
  { key: 'benefits', fields: [...BENEFITS] },
]

interface DraftRow {
  employment_id: number
  month: number
  money: Record<MoneyField, string>
  insurance_days: string
  excluded_days: string
  worked_days: string
  worked_hours: string
  pension_participation: boolean
  payout_date: string
  confirmed_zero: boolean
  stored: boolean
}

const people = ref<PayrollPersonOption[]>([])
const employeeId = ref<number | null>(null)
const entry = ref<PayrollTakeoverManualEntry | null>(null)
const drafts = ref<DraftRow[]>([])
const sourceReference = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')

const employmentCodes = computed(() => {
  const map: Record<number, string> = {}
  for (const employment of entry.value?.employments ?? []) map[employment.id] = employment.code
  return map
})

function toDraft(row: PayrollTakeoverManualRow): DraftRow {
  const money = {} as Record<MoneyField, string>
  for (const field of AMOUNTS) money[field] = minorToCrowns(row[field] ?? 0)
  const empty = MONEY.every((field: IncomeField) => row[field] === 0)
  return {
    employment_id: row.employment_id,
    month: row.month,
    money,
    insurance_days: row.insurance_days === 0 ? '' : String(row.insurance_days),
    excluded_days: row.excluded_days === 0 ? '' : String(row.excluded_days),
    worked_days: hundredthsToDays(row.worked_days_hundredths),
    worked_hours: minutesToHours(row.worked_minutes),
    pension_participation: row.pension_participation,
    payout_date: row.payout_date ?? '',
    // Uložený nulový měsíc byl při zadání potvrzený, jinak by uložený nebyl.
    confirmed_zero: empty && row.stored === true,
    stored: row.stored === true,
  }
}

function rowIsEmpty(row: DraftRow): boolean {
  return MONEY.every(field => {
    const minor = crownsToMinor(row.money[field])
    return minor !== null && minor === 0
  })
}

async function loadPeople(): Promise<void> {
  try {
    people.value = await payrollApi.peopleOptions()
  } catch {
    people.value = []
  }
}

async function load(): Promise<void> {
  error.value = ''
  if (employeeId.value === null) {
    entry.value = null
    drafts.value = []
    return
  }
  loading.value = true
  try {
    entry.value = await payrollTakeoverWagesApi.manualForm(props.year, employeeId.value)
    drafts.value = entry.value.rows.map(toDraft)
    sourceReference.value = entry.value.source_reference
  } catch (exception) {
    entry.value = null
    drafts.value = []
    error.value = apiErrorMessage(exception, t('payroll.takeover_manual.load_failed'))
  } finally {
    loading.value = false
  }
}

function payload(): PayrollTakeoverManualRow[] | null {
  const rows: PayrollTakeoverManualRow[] = []
  for (const draft of drafts.value) {
    const values = {} as Record<MoneyField, number>
    for (const field of AMOUNTS) {
      const minor = crownsToMinor(draft.money[field])
      if (minor === null) {
        error.value = t('payroll.takeover_manual.amount_invalid', {
          month: draft.month,
          field: t(`payroll.takeover_manual.field.${field}`),
        })
        return null
      }
      values[field] = minor
    }
    const insuranceDays = wholeDays(draft.insurance_days)
    const excludedDays = wholeDays(draft.excluded_days)
    const workedDays = daysToHundredths(draft.worked_days)
    const workedMinutes = hoursToMinutes(draft.worked_hours)
    if (insuranceDays === null || excludedDays === null || workedDays === null || workedMinutes === null) {
      error.value = t('payroll.takeover_manual.days_invalid', { month: draft.month })
      return null
    }
    const empty = rowIsEmpty(draft)
    if (empty && !draft.confirmed_zero) {
      error.value = t('payroll.takeover_manual.empty_month', {
        month: draft.month,
        employment: employmentCodes.value[draft.employment_id] ?? '',
      })
      return null
    }
    rows.push({
      employment_id: draft.employment_id,
      month: draft.month,
      ...values,
      insurance_days: insuranceDays,
      excluded_days: excludedDays,
      worked_days_hundredths: workedDays,
      worked_minutes: workedMinutes,
      pension_participation: draft.pension_participation,
      payout_date: draft.payout_date === '' ? null : draft.payout_date,
      ...(empty ? { confirmed_zero: true } : {}),
    })
  }
  return rows
}

async function save(): Promise<void> {
  if (saving.value || employeeId.value === null) return
  error.value = ''
  const rows = payload()
  if (rows === null) return
  saving.value = true
  try {
    entry.value = await payrollTakeoverWagesApi.manualSave(props.year, employeeId.value, {
      rows,
      source_reference: sourceReference.value.trim(),
    })
    drafts.value = entry.value.rows.map(toDraft)
    toast.success(t('payroll.takeover_manual.saved'))
    emit('saved')
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.takeover_manual.save_failed'))
  } finally {
    saving.value = false
  }
}

function employeeFromRoute(): number | null {
  const raw = route?.query.employee
  const value = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isInteger(value) && value > 0 ? value : null
}

watch(employeeId, () => { void load() })
watch(() => props.year, () => { void load() })
watch(() => route?.query.employee, () => {
  const requested = employeeFromRoute()
  if (requested !== null) employeeId.value = requested
})

onMounted(async () => {
  await loadPeople()
  employeeId.value = employeeFromRoute()
})
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="takeover-manual">
    <h3 class="font-semibold text-neutral-900">{{ t('payroll.takeover_manual.title') }}</h3>
    <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.takeover_manual.hint') }}</p>

    <label class="mt-3 block max-w-md text-sm text-neutral-600">
      <span class="mb-1 block text-xs font-medium">{{ t('payroll.takeover_manual.employee') }}</span>
      <select
        v-model="employeeId"
        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
        data-test="takeover-manual-employee"
      >
        <option :value="null">{{ t('payroll.takeover_manual.employee_placeholder') }}</option>
        <option v-for="person in people" :key="person.id" :value="person.id">{{ person.full_name }}</option>
      </select>
    </label>

    <div v-if="loading" class="mt-4 h-24 animate-pulse rounded-lg bg-neutral-100" />

    <template v-else-if="entry">
      <p
        v-if="entry.takeover_months.length === 0"
        class="mt-4 rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600"
        data-test="takeover-manual-no-months"
      >{{ t('payroll.takeover_manual.no_takeover_months', { year: entry.year }) }}</p>
      <p
        v-else-if="entry.employments.length === 0"
        class="mt-4 rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600"
        data-test="takeover-manual-no-employment"
      >{{ t('payroll.takeover_manual.no_employment', { months: monthRanges(entry.takeover_months) }) }}</p>

      <template v-else>
        <p class="mt-4 text-xs text-neutral-600">
          {{ t('payroll.takeover_manual.months_hint', { months: monthRanges(entry.takeover_months) }) }}
        </p>
        <p
          v-if="entry.locked"
          class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
          data-test="takeover-manual-locked"
        >{{ entry.lock_reason }}</p>
        <p
          v-else-if="entry.partial_lock_reason"
          class="mt-3 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700"
          data-test="takeover-manual-partial-lock"
        >{{ t('payroll.takeover_manual.partial_lock', { reason: entry.partial_lock_reason }) }}</p>

        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead>
              <tr class="text-left text-neutral-500">
                <th class="py-1 pr-2" colspan="2" />
                <th
                  v-for="group in GROUPS"
                  :key="group.key"
                  :colspan="group.fields.length + (group.key === 'wage' ? 1 : 0)"
                  class="border-b border-neutral-200 px-2 pb-1 font-semibold uppercase tracking-wide text-neutral-600"
                >{{ t(`payroll.takeover_manual.group.${group.key}`) }}</th>
                <th colspan="5" class="border-b border-neutral-200 px-2 pb-1 font-semibold uppercase tracking-wide text-neutral-600">
                  {{ t('payroll.takeover_manual.group.durations') }}
                </th>
                <th />
              </tr>
              <tr class="text-left text-neutral-500">
                <th class="py-1 pr-2 font-medium">{{ t('payroll.takeover_manual.month') }}</th>
                <th class="py-1 pr-2 font-medium">{{ t('payroll.takeover_manual.employment') }}</th>
                <template v-for="group in GROUPS" :key="`head-${group.key}`">
                  <th v-for="field in group.fields" :key="field" class="px-1 py-1 font-medium">
                    {{ t(`payroll.takeover_manual.field.${field}`) }}
                  </th>
                  <th v-if="group.key === 'wage'" class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.payout_date') }}</th>
                </template>
                <th class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.pension_participation') }}</th>
                <th class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.insurance_days') }}</th>
                <th class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.excluded_days') }}</th>
                <th class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.worked_days') }}</th>
                <th class="px-1 py-1 font-medium">{{ t('payroll.takeover_manual.field.worked_hours') }}</th>
                <th class="whitespace-nowrap px-1 py-1 font-medium" :title="t('payroll.people.openings.explicit.confirm_hint')">
                  {{ t('payroll.people.openings.explicit.confirm') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="draft in drafts"
                :key="`${draft.employment_id}-${draft.month}`"
                class="border-t border-neutral-100"
                :data-test="`takeover-manual-row-${draft.employment_id}-${draft.month}`"
              >
                <th class="py-1 pr-2 text-left font-normal text-neutral-700">{{ draft.month }}</th>
                <td class="whitespace-nowrap py-1 pr-2 text-neutral-700">{{ employmentCodes[draft.employment_id] }}</td>
                <template v-for="group in GROUPS" :key="`cell-${group.key}`">
                  <td v-for="field in group.fields" :key="field" class="px-1 py-1">
                    <input
                      v-model="draft.money[field]"
                      inputmode="decimal"
                      :disabled="!canWrite || entry.locked || saving"
                      :data-test="`takeover-manual-${draft.employment_id}-${draft.month}-${field}`"
                      class="w-24 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100"
                    >
                  </td>
                  <td v-if="group.key === 'wage'" class="px-1 py-1">
                    <input
                      v-model="draft.payout_date"
                      type="date"
                      :disabled="!canWrite || entry.locked || saving"
                      class="w-36 rounded-md border border-neutral-300 bg-surface px-2 py-1 disabled:bg-neutral-100"
                    >
                  </td>
                </template>
                <td class="px-1 py-1 text-center">
                  <input
                    v-model="draft.pension_participation"
                    type="checkbox"
                    :disabled="!canWrite || entry.locked || saving"
                    class="h-4 w-4 rounded border-neutral-300"
                  >
                </td>
                <td class="px-1 py-1">
                  <input v-model="draft.insurance_days" inputmode="numeric" :disabled="!canWrite || entry.locked || saving" class="w-14 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100">
                </td>
                <td class="px-1 py-1">
                  <input v-model="draft.excluded_days" inputmode="numeric" :disabled="!canWrite || entry.locked || saving" class="w-14 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100">
                </td>
                <td class="px-1 py-1">
                  <input v-model="draft.worked_days" inputmode="decimal" :disabled="!canWrite || entry.locked || saving" class="w-16 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100">
                </td>
                <td class="px-1 py-1">
                  <input v-model="draft.worked_hours" inputmode="decimal" :disabled="!canWrite || entry.locked || saving" class="w-16 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right tabular-nums disabled:bg-neutral-100">
                </td>
                <td class="px-1 py-1 text-center">
                  <input
                    v-model="draft.confirmed_zero"
                    type="checkbox"
                    :disabled="!canWrite || entry.locked || saving || !rowIsEmpty(draft)"
                    :aria-label="t('payroll.people.openings.explicit.confirm_aria', { month: draft.month })"
                    :data-test="`takeover-manual-${draft.employment_id}-${draft.month}-confirmed-zero`"
                    class="h-4 w-4 rounded border-neutral-300 disabled:opacity-40"
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.takeover_manual.per_person_hint') }}</p>

        <label class="mt-3 block text-xs text-neutral-600">
          {{ t('payroll.people.openings.source') }}
          <input
            v-model="sourceReference"
            :disabled="!canWrite || entry.locked || saving"
            :placeholder="t('payroll.people.openings.source_placeholder')"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100"
          >
        </label>

        <p
          v-if="error"
          class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
          role="alert"
          data-test="takeover-manual-error"
        >{{ error }}</p>

        <div v-if="canWrite && !entry.locked" class="mt-3 flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="saving" @click="load">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="saving"
            data-test="takeover-manual-save"
            @click="save"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('payroll.takeover_manual.save') }}
          </button>
        </div>
      </template>
    </template>

    <p
      v-else-if="error"
      class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
      role="alert"
    >{{ error }}</p>
  </section>
</template>

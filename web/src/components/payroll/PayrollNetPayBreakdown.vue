<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollRunResultPerson } from '@/api/payroll'
import { payrollDeductionsApi, type NetResultBreakdown } from '@/api/payrollDeductions'
import { protectedAmountIsUnattested } from '@/pages/payroll/enforcementEvidenceScope'
import PayrollPersonPicker from '@/components/payroll/PayrollPersonPicker.vue'

const props = withDefaults(defineProps<{
  revisionId: number | null
  approved: boolean
  people: PayrollRunResultPerson[]
  personNames?: Record<number, string>
}>(), {
  personNames: () => ({}),
})

const { t, locale } = useI18n()
const selectedEmployeeId = ref<number | null>(null)
const breakdown = ref<NetResultBreakdown | null>(null)
const loading = ref(false)
const error = ref('')
let requestSequence = 0

const netPeople = computed(() => props.people.filter(
  person => person.statutory?.status === 'calculated',
))

const available = computed(() =>
  props.approved && props.revisionId !== null && netPeople.value.length > 0,
)
const personOptions = computed(() => netPeople.value.map(person => ({
  value: person.employee_id,
  label: personLabel(person),
})))

function personLabel(person: PayrollRunResultPerson): string {
  return props.personNames[person.employee_id]
    || t('payroll.runs.net.person_fallback', { id: person.employee_id })
}

function money(value: number | null | undefined): string {
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
  }).format((value ?? 0) / 100)
}

/**
 * Proč se dohoda o srážkách nesrazila.
 *
 * Nesražená částka sama o sobě neřekne, co s tím: „nevešlo se to do
 * nezabavitelné částky" se řeší penězi (příště bude vyšší mzda), kdežto
 * „nezabavitelná částka stojí na nároku, který nikdo nedoložil" se řeší
 * doložením nároku — a do té doby se nesrazí nic, ať je mzda jakákoli.
 * V číslech vypadají obě situace stejně, proto věta jen u té druhé; u běžného
 * nedostatku kapacity by obecný komentář jen zopakoval, co je z částky vidět.
 *
 * Revize bez uloženého rozsahu (`null`) mlčí — tehdejší kód evidenci vyžadoval
 * bezpodmínečně, takže o jejím rozsahu netvrdil nic a dopočítat se to nesmí.
 */
const unappliedReason = computed<string | null>(() => {
  const scope = breakdown.value?.enforcement_evidence_source ?? null
  if (scope === null || !protectedAmountIsUnattested(scope)) return null
  const claim = scope.dependants === 'nothing_withheld'
    ? (scope.spouse === 'nothing_withheld' ? 'both' : 'dependants')
    : 'spouse'
  return t('payroll.runs.net.unapplied_unattested', {
    claim: t(`payroll.runs.net.unapplied_unattested_claim.${claim}`),
  })
})

async function loadBreakdown(employeeId: number | null) {
  breakdown.value = null
  error.value = ''
  if (!available.value || employeeId === null || props.revisionId === null) return
  const sequence = ++requestSequence
  loading.value = true
  try {
    const loaded = await payrollDeductionsApi.netResult(props.revisionId, employeeId)
    if (sequence !== requestSequence) return
    breakdown.value = loaded
  } catch (e: any) {
    if (sequence !== requestSequence) return
    error.value = e?.response?.data?.error?.message || t('payroll.runs.net.load_failed')
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

watch(netPeople, (people) => {
  if (!people.some(person => person.employee_id === selectedEmployeeId.value)) {
    selectedEmployeeId.value = people[0]?.employee_id ?? null
  }
}, { immediate: true })

watch(
  [selectedEmployeeId, () => props.revisionId, available],
  () => void loadBreakdown(selectedEmployeeId.value),
  { immediate: true },
)
</script>

<template>
  <section
    v-if="available"
    class="mt-5 overflow-hidden rounded-xl border border-payroll-200 bg-surface"
    data-testid="net-pay-breakdown"
  >
    <div class="border-b border-payroll-100 bg-payroll-50/50 px-4 py-4 sm:px-5">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.net.title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.runs.net.subtitle') }}</p>
    </div>

    <PayrollPersonPicker
      v-model="selectedEmployeeId"
      :options="personOptions"
      :selector-label="t('payroll.runs.net.people_tabs')"
    />

    <div v-if="loading" class="space-y-3 p-4 sm:p-5">
      <div v-for="index in 3" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <p
      v-else-if="error"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="net-breakdown-error"
    >
      {{ error }}
    </p>

    <div v-else-if="breakdown" class="space-y-5 p-4 sm:p-5">
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.net.gross') }}</dt>
          <dd class="mt-1 font-semibold tabular-nums text-neutral-900">{{ money(breakdown.income.gross_minor) }}</dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.net.contributions') }}</dt>
          <dd class="mt-1 font-semibold tabular-nums text-neutral-900">
            {{ money(breakdown.contributions.employee_social_minor + breakdown.contributions.employee_health_minor) }}
          </dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.net.tax') }}</dt>
          <dd class="mt-1 font-semibold tabular-nums text-neutral-900">
            {{ money(breakdown.tax.advance_minor + breakdown.tax.withholding_minor - breakdown.tax.bonus_minor) }}
          </dd>
        </div>
        <div class="rounded-lg bg-success-50 p-3">
          <dt class="text-xs text-success-700">{{ t('payroll.runs.net.payable') }}</dt>
          <dd class="mt-1 font-semibold tabular-nums text-success-800">
            {{ money(breakdown.payable_after_enforcement_minor) }}
          </dd>
        </div>
      </dl>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.net.steps_title') }}</h4>
        <dl class="mt-3 space-y-2 text-sm">
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.cash_income') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(breakdown.income.cash_minor) }}</dd>
          </div>
          <div v-if="breakdown.income.non_cash_minor" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.non_cash_income') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(breakdown.income.non_cash_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.social') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.contributions.employee_social_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.health') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.contributions.employee_health_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.advance_tax') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.tax.advance_minor) }}</dd>
          </div>
          <div v-if="breakdown.tax.withholding_minor" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.withholding_tax') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.tax.withholding_minor) }}</dd>
          </div>
          <div v-if="breakdown.tax.bonus_minor" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.bonus') }}</dt>
            <dd class="font-medium tabular-nums">+ {{ money(breakdown.tax.bonus_minor) }}</dd>
          </div>
          <div v-if="breakdown.correction_minor" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.correction') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(breakdown.correction_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="font-medium text-neutral-800">{{ t('payroll.runs.net.before_deductions') }}</dt>
            <dd class="font-semibold tabular-nums">{{ money(breakdown.net_before_deductions_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.deductions_total') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.deducted_minor) }}</dd>
          </div>
          <div v-if="breakdown.enforcement_withheld_minor" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
            <dt class="text-neutral-600">{{ t('payroll.runs.net.enforcement') }}</dt>
            <dd class="font-medium tabular-nums">− {{ money(breakdown.enforcement_withheld_minor) }}</dd>
          </div>
          <div class="flex flex-wrap justify-between gap-2">
            <dt class="font-medium text-success-700">{{ t('payroll.runs.net.payable') }}</dt>
            <dd class="font-semibold tabular-nums text-success-700">{{ money(breakdown.payable_after_enforcement_minor) }}</dd>
          </div>
        </dl>
      </section>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.net.deductions_title') }}</h4>
        <div v-if="breakdown.deductions.length" class="mt-3 space-y-2">
          <article
            v-for="deduction in breakdown.deductions"
            :key="deduction.deduction_reference"
            class="grid gap-1 rounded-lg bg-neutral-50 p-3 text-sm sm:grid-cols-[1fr_auto]"
          >
            <div class="min-w-0">
              <p class="break-words font-medium text-neutral-900">{{ deduction.title }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ t('payroll.runs.net.priority', { priority: deduction.priority_no }) }}
                · {{ t(`payroll.deductions.kinds.${deduction.deduction_kind}`) }}
              </p>
              <p
                v-if="deduction.active === false"
                class="mt-0.5 text-xs text-neutral-500"
                data-test="deduction-suspended"
              >
                {{ t('payroll.runs.net.deduction_suspended') }}
              </p>
              <p v-else-if="deduction.unapplied_minor" class="mt-0.5 text-xs text-warning-700">
                {{ t('payroll.runs.net.unapplied', { value: money(deduction.unapplied_minor) }) }}
              </p>
              <p
                v-if="deduction.unapplied_minor && unappliedReason"
                class="mt-0.5 text-xs text-neutral-500"
                data-test="unapplied-reason"
              >
                {{ unappliedReason }}
              </p>
            </div>
            <p class="font-medium tabular-nums sm:self-center">{{ money(deduction.applied_minor) }}</p>
          </article>
        </div>
        <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.runs.net.no_deductions') }}</p>
      </section>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.net.allocations_title') }}</h4>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.runs.net.allocations_hint') }}</p>
        <div v-if="breakdown.allocation_status === 'resolved'" class="mt-3 space-y-2">
          <article
            v-for="allocation in breakdown.allocations"
            :key="allocation.allocation_reference"
            class="grid gap-1 rounded-lg bg-neutral-50 p-3 text-sm sm:grid-cols-[1fr_auto]"
          >
            <div class="min-w-0">
              <p class="break-words font-medium text-neutral-900">
                {{ allocation.destination_kind === 'cash'
                  ? t('payroll.runs.net.destination_cash')
                  : (allocation.destination_label || t('payroll.runs.net.destination_bank')) }}
              </p>
              <p class="mt-0.5 break-words text-xs text-neutral-500">
                {{ allocation.destination_masked || t('payroll.runs.net.destination_cash') }}
              </p>
            </div>
            <p class="font-medium tabular-nums sm:self-center">{{ money(allocation.amount_minor) }}</p>
          </article>
          <p class="flex flex-wrap justify-between gap-2 border-t border-neutral-200 pt-2 text-sm">
            <span class="font-medium text-neutral-800">{{ t('payroll.runs.net.allocations_total') }}</span>
            <span class="font-semibold tabular-nums">{{ money(breakdown.allocations_total_minor) }}</span>
          </p>
        </div>
        <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.runs.net.no_allocations') }}</p>
      </section>
    </div>
  </section>
</template>

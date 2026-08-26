<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  PayrollIncomeTaxRelationshipResult,
  PayrollIncomeTaxResult,
  PayrollRunResultPerson,
} from '@/api/payroll'
import PayrollPersonPicker from '@/components/payroll/PayrollPersonPicker.vue'

const props = withDefaults(defineProps<{
  people: PayrollRunResultPerson[]
  personNames?: Record<number, string>
}>(), {
  personNames: () => ({}),
})

const { t, locale } = useI18n()
const selectedEmployeeId = ref<number | null>(null)

const taxPeople = computed(() => props.people.filter(
  (person): person is PayrollRunResultPerson & {
    statutory: NonNullable<PayrollRunResultPerson['statutory']> & {
      income_tax: PayrollIncomeTaxResult
    }
  } => person.statutory?.income_tax !== undefined,
))

watch(taxPeople, (people) => {
  if (!people.some(person => person.employee_id === selectedEmployeeId.value)) {
    selectedEmployeeId.value = people[0]?.employee_id ?? null
  }
}, { immediate: true })

const selectedPerson = computed(() =>
  taxPeople.value.find(person => person.employee_id === selectedEmployeeId.value)
  ?? taxPeople.value[0]
  ?? null,
)
const personOptions = computed(() => taxPeople.value.map(person => ({
  value: person.employee_id,
  label: personLabel(person),
})))

const tax = computed(() => selectedPerson.value?.statutory.income_tax ?? null)
const advance = computed(() => tax.value?.advance_tax ?? null)
const resultStatus = computed<'calculated' | 'manual_review' | 'error'>(() => {
  if (selectedPerson.value?.statutory.status === 'error') return 'error'
  if (
    selectedPerson.value?.statutory.status === 'manual_review'
    || tax.value?.status === 'manual-review'
  ) return 'manual_review'
  return 'calculated'
})
const manualReview = computed(() =>
  resultStatus.value !== 'calculated',
)
const taxableBase = computed(() =>
  tax.value?.relationships.reduce(
    (sum, relationship) => sum + relationship.taxable_base_minor_units,
    0,
  ) ?? 0,
)
const issues = computed(() => Array.from(new Set([
  ...(selectedPerson.value?.statutory.issues ?? []),
  ...(tax.value?.issues ?? []),
])))

const knownIssues = new Set([
  'disability-credit-conflict',
  'duplicate-employment-relationship-reference',
  'duplicate-tax-credit-claim',
  'duplicate-tax-child-claim',
  'income-component-exemption-evidence-unverified',
  'income-component-tax-treatment-unverified',
  'negative-relationship-tax-base',
  'nonresident-monthly-credit-not-supported',
  'nonresident-monthly-child-credit-not-supported',
  'other-withholding-eligibility-unverified',
  'prior-period-tax-correction-requires-revision',
  'relationship-tax-classification-conflict',
  'tax-credit-evidence-unverified',
  'tax-credit-requires-signed-declaration',
  'tax-declaration-conflict',
  'tax-declaration-evidence-missing',
  'tax-declaration-unverified',
  'tax-child-concurrent-claim-unresolved',
  'tax-child-evidence-unverified',
  'tax-child-order-conflict',
  'tax-child-order-gap',
  'tax-child-requires-signed-declaration',
  'tax-child-shared-household-unverified',
  'tax-residence-evidence-not-effective',
  'tax-residence-unverified',
  'unsupported-tax-year',
])

function personLabel(person: PayrollRunResultPerson): string {
  return props.personNames[person.employee_id]
    || t('payroll.runs.tax.person_fallback', { id: person.employee_id })
}

function money(value: number | undefined): string {
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
  }).format((value ?? 0) / 100)
}

function percentage(decimal: string): string {
  const value = Number(decimal)
  return Number.isFinite(value)
    ? new Intl.NumberFormat(locale.value, {
      style: 'percent',
      maximumFractionDigits: 2,
    }).format(value)
    : decimal
}

function regime(): 'advance' | 'withholding' | 'mixed' | 'manual_review' {
  if (manualReview.value) return 'manual_review'
  const hasAdvance = tax.value?.advance_tax !== null
  const hasWithholding = (tax.value?.withholding_groups.length ?? 0) > 0
  if (hasAdvance && hasWithholding) return 'mixed'
  return hasWithholding ? 'withholding' : 'advance'
}

function relationshipKind(kind: PayrollIncomeTaxRelationshipResult['kind']): string {
  return t(`payroll.runs.tax.relationship_kind.${kind}`)
}

function relationshipRegime(value: PayrollIncomeTaxRelationshipResult['regime']): string {
  return t(`payroll.runs.tax.regime.${value === 'manual-review' ? 'manual_review' : value}`)
}

function issueLabel(code: string): string {
  return knownIssues.has(code)
    ? t(`payroll.runs.tax.issues.${code}`)
    : t('payroll.runs.tax.issues.unknown', { code })
}

function unavailableAdvanceLabel(): string {
  return t(`payroll.runs.tax.${manualReview.value ? 'not_calculated' : 'not_applicable'}`)
}
</script>

<template>
  <section
    v-if="taxPeople.length"
    class="mt-5 overflow-hidden rounded-xl border border-payroll-200 bg-surface"
    data-testid="income-tax-breakdown"
  >
    <div class="border-b border-payroll-100 bg-payroll-50/50 px-4 py-4 sm:px-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.tax.title') }}</h3>
          <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.runs.tax.subtitle') }}</p>
        </div>
        <span
          v-if="selectedPerson"
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="resultStatus === 'error'
            ? 'bg-danger-50 text-danger-700'
            : manualReview
              ? 'bg-warning-100 text-warning-800'
              : 'bg-success-50 text-success-700'"
          data-testid="tax-status"
        >
          {{ t(`payroll.runs.tax.status.${resultStatus}`) }}
        </span>
      </div>
    </div>

    <PayrollPersonPicker
      v-model="selectedEmployeeId"
      :options="personOptions"
      :selector-label="t('payroll.runs.tax.people_tabs')"
    />

    <div v-if="selectedPerson && tax" class="space-y-5 p-4 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
            {{ t('payroll.runs.tax.calculation_date') }}
          </p>
          <p class="mt-1 text-sm font-medium text-neutral-900">{{ tax.calculation_date }}</p>
        </div>
        <span class="rounded-full bg-payroll-50 px-3 py-1 text-sm font-medium text-payroll-700">
          {{ t(`payroll.runs.tax.regime.${regime()}`) }}
        </span>
      </div>

      <div
        v-if="manualReview"
        class="rounded-lg border border-warning-200 bg-warning-50 p-4"
        role="alert"
        data-testid="manual-review-reasons"
      >
        <p class="font-medium text-warning-900">{{ t('payroll.runs.tax.manual_review_title') }}</p>
        <p class="mt-1 text-sm text-warning-800">{{ t('payroll.runs.tax.manual_review_hint') }}</p>
        <ul v-if="issues.length" class="mt-3 list-disc space-y-1 pl-5 text-sm text-warning-900">
          <li v-for="issue in issues" :key="issue">{{ issueLabel(issue) }}</li>
        </ul>
        <p v-else class="mt-3 text-sm text-warning-900">
          {{ t('payroll.runs.tax.manual_review_without_reason') }}
        </p>
      </div>

      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.tax.taxable_base') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900">{{ money(taxableBase) }}</dd>
        </div>
        <div class="rounded-lg bg-payroll-50 p-3">
          <dt class="text-xs text-payroll-700">{{ t('payroll.runs.tax.rounded_base') }}</dt>
          <dd class="mt-1 font-semibold text-payroll-800">
            {{ advance ? money(advance.rounded_tax_base_minor_units) : unavailableAdvanceLabel() }}
          </dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.tax.tax_before_credits') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900">
            {{ advance ? money(advance.tax_before_credits_minor_units) : unavailableAdvanceLabel() }}
          </dd>
        </div>
        <div class="rounded-lg bg-success-50 p-3">
          <dt class="text-xs text-success-700">{{ t('payroll.runs.tax.tax_after_credits') }}</dt>
          <dd class="mt-1 font-semibold text-success-800">
            {{ advance ? money(advance.tax_after_credits_minor_units) : unavailableAdvanceLabel() }}
          </dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.runs.tax.withholding_tax') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900">
            {{ manualReview && !tax.withholding_groups.length
              ? t('payroll.runs.tax.not_calculated')
              : money(tax.withholding_tax_minor_units) }}
          </dd>
        </div>
        <div class="rounded-lg bg-payroll-50 p-3">
          <dt class="text-xs text-payroll-700">{{ t('payroll.runs.tax.tax_bonus') }}</dt>
          <dd class="mt-1 font-semibold text-payroll-800">
            {{ advance ? money(advance.tax_bonus_minor_units) : unavailableAdvanceLabel() }}
          </dd>
        </div>
      </dl>

      <section v-if="advance" class="rounded-lg border border-neutral-200">
        <div class="border-b border-neutral-200 px-4 py-3">
          <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.tax.rounding_title') }}</h4>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.runs.tax.rounding_detail', {
              base: money(advance.taxable_income_minor_units),
              rounded: money(advance.rounded_tax_base_minor_units),
              difference: money(advance.rounded_tax_base_minor_units - advance.taxable_income_minor_units),
            }) }}
          </p>
        </div>

        <div class="hidden overflow-x-auto md:block">
          <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-4 py-3">{{ t('payroll.runs.tax.band') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.runs.tax.band_base') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.runs.tax.rate') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.runs.tax.band_tax') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(step, index) in advance.rate_steps" :key="step.label">
                <td class="px-4 py-3 font-medium text-neutral-900">
                  {{ t('payroll.runs.tax.band_number', { number: index + 1 }) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ money(step.input_minor_units) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ percentage(step.rate.decimal) }}</td>
                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ money(step.output_minor_units) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="divide-y divide-neutral-100 md:hidden">
          <article v-for="(step, index) in advance.rate_steps" :key="step.label" class="space-y-2 p-4">
            <h5 class="font-medium text-neutral-900">
              {{ t('payroll.runs.tax.band_number', { number: index + 1 }) }}
            </h5>
            <dl class="grid grid-cols-2 gap-2 text-sm">
              <dt class="text-neutral-500">{{ t('payroll.runs.tax.band_base') }}</dt>
              <dd class="text-right font-medium tabular-nums">{{ money(step.input_minor_units) }}</dd>
              <dt class="text-neutral-500">{{ t('payroll.runs.tax.rate') }}</dt>
              <dd class="text-right font-medium tabular-nums">{{ percentage(step.rate.decimal) }}</dd>
              <dt class="text-neutral-500">{{ t('payroll.runs.tax.band_tax') }}</dt>
              <dd class="text-right font-medium tabular-nums">{{ money(step.output_minor_units) }}</dd>
            </dl>
          </article>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.tax.credits_title') }}</h4>
        <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
          <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
            <dt class="text-sm text-neutral-600">{{ t('payroll.runs.tax.non_refundable_claimed') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(tax.claimed_non_refundable_credits_minor_units) }}</dd>
          </div>
          <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
            <dt class="text-sm text-neutral-600">{{ t('payroll.runs.tax.non_refundable_applied') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(tax.applied_non_refundable_credits_minor_units) }}</dd>
          </div>
          <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
            <dt class="text-sm text-neutral-600">{{ t('payroll.runs.tax.children_claimed') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(tax.claimed_child_credit_minor_units) }}</dd>
          </div>
          <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
            <dt class="text-sm text-neutral-600">{{ t('payroll.runs.tax.children_applied') }}</dt>
            <dd class="font-medium tabular-nums">{{ money(tax.applied_child_credit_minor_units) }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="tax.relationships.length" class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.tax.relationships_title') }}</h4>
        <div class="mt-3 space-y-3">
          <article
            v-for="relationship in tax.relationships"
            :key="relationship.relationship_reference"
            class="grid gap-2 rounded-lg bg-neutral-50 p-3 text-sm sm:grid-cols-[1fr_auto_auto]"
          >
            <div>
              <p class="font-medium text-neutral-900">{{ relationshipKind(relationship.kind) }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ relationship.relationship_reference }}</p>
            </div>
            <p class="font-medium tabular-nums sm:self-center">
              {{ money(relationship.taxable_base_minor_units) }}
            </p>
            <span class="w-fit rounded-full bg-surface px-2.5 py-1 text-xs font-medium text-payroll-700 sm:self-center">
              {{ relationshipRegime(relationship.regime) }}
            </span>
          </article>
        </div>
      </section>

      <section v-if="tax.withholding_groups.length" class="rounded-lg border border-neutral-200 p-4">
        <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.tax.withholding_groups_title') }}</h4>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <article
            v-for="group in tax.withholding_groups"
            :key="group.group"
            class="rounded-lg bg-neutral-50 p-3"
          >
            <p class="font-medium text-neutral-900">
              {{ t(`payroll.runs.tax.withholding_group.${group.group}`) }}
            </p>
            <dl class="mt-2 space-y-1 text-sm">
              <div class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('payroll.runs.tax.band_base') }}</dt>
                <dd class="font-medium tabular-nums">{{ money(group.base_minor_units) }}</dd>
              </div>
              <div class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('payroll.runs.tax.rate') }}</dt>
                <dd class="font-medium tabular-nums">{{ percentage(group.rate_step.rate.decimal) }}</dd>
              </div>
              <div class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('payroll.runs.tax.withholding_tax') }}</dt>
                <dd class="font-medium tabular-nums">{{ money(group.tax_minor_units) }}</dd>
              </div>
            </dl>
          </article>
        </div>
      </section>
    </div>
  </section>
</template>

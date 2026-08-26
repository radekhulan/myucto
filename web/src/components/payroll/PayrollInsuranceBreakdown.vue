<script setup lang="ts">
/**
 * MZ-10-W07 / MZ-11-W07 — „jak vzniklo sociální a zdravotní pojistné".
 *
 * Daň a čistá mzda svůj rozklad měly, pojistné ne: účetní viděl výslednou částku
 * a neměl jak ověřit, odkud se vzala. Za nedoložitelný výpočet nemůže převzít
 * odpovědnost a při kontrole nemá čím argumentovat.
 *
 * Komponenta NIC nepočítá. Každé číslo i sazba jsou přenesené z neměnného
 * zákonného výsledku toho běhu, který částku vydal. Kde vysvětlení chybí (starší
 * revize bez uložených mezikroků), řekne se to větou — prázdno ani odhad ne.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollRunResultPerson } from '@/api/payroll'
import {
  payrollInsuranceApi,
  type PayrollHealthBreakdown,
  type PayrollInsuranceBreakdown,
  type PayrollInsuranceStep,
  type PayrollSocialBreakdown,
} from '@/api/payrollInsurance'
import { btnOutlineSm } from '@/components/ui/buttonStyles'
import PayrollPersonPicker from '@/components/payroll/PayrollPersonPicker.vue'

const props = withDefaults(defineProps<{
  revisionId: number | null
  people: PayrollRunResultPerson[]
  personNames?: Record<number, string>
}>(), {
  personNames: () => ({}),
})

const { t, locale } = useI18n()
const selectedEmployeeId = ref<number | null>(null)
const breakdown = ref<PayrollInsuranceBreakdown | null>(null)
const loading = ref(false)
const error = ref('')
const showRelationships = ref(false)
let requestSequence = 0

const insurancePeople = computed(() => props.people.filter(person => person.statutory !== undefined))

const available = computed(() => props.revisionId !== null && insurancePeople.value.length > 0)
const personOptions = computed(() => insurancePeople.value.map(person => ({
  value: person.employee_id,
  label: personLabel(person),
})))

const social = computed(() =>
  breakdown.value?.social.available ? breakdown.value.social as PayrollSocialBreakdown : null,
)
const health = computed(() =>
  breakdown.value?.health.available ? breakdown.value.health as PayrollHealthBreakdown : null,
)

/**
 * Zdravotní pojistné bez uložené sazby: revize je starší než okamžik, kdy se
 * mezikroky začaly ukládat. Dopočítat sazbu z dnešních pravidel by znamenalo
 * popsat jiný výpočet, než jaký dal uloženou částku — tak to radši řekneme.
 */
const healthRateMissing = computed(() => health.value?.contribution.rate_source === 'not_recorded')

/** Pojistné nevzniklo — sazba nechybí, prostě není co čím násobit. */
const healthNotApplicable = computed(() =>
  health.value?.contribution.rate_source === 'not_applicable',
)

/**
 * Sazba se neuložila, ale je DOLOŽENÁ: vzala se ze sady pravidel zmrazené v té
 * revizi (shoda otisku bajt na bajt) a po zaokrouhlení dá tutéž uloženou částku.
 * Uživatel to musí vidět — jinak by rekonstrukci četl jako uložený mezikrok.
 */
const healthRateReconstructed = computed(() =>
  health.value?.contribution.rate_source === 'reconstructed',
)

/** Rozdělení pojistného zaměstnavatele na osobu — alokace, ne zákonná částka. */
const employerAllocation = computed(() => social.value?.employer.allocation ?? null)

/** Rozpad firemního pojistného po písmenech § 5a odst. 1 — a), b), c). */
const employerCategories = computed(() => social.value?.employer.categories ?? [])

function personLabel(person: PayrollRunResultPerson): string {
  return props.personNames[person.employee_id]
    || t('payroll.runs.insurance.person_fallback', { id: person.employee_id })
}

function money(value: number | null | undefined): string {
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
  }).format((value ?? 0) / 100)
}

function moneyOrUnknown(value: number | null | undefined): string {
  return value === null || value === undefined
    ? t('payroll.runs.insurance.not_calculated')
    : money(value)
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

/** Věta „14 000,00 Kč × 6,5 % = 910,00 Kč (zaokrouhleno nahoru na celé Kč)". */
function stepSentence(step: PayrollInsuranceStep, rounded: number | null): string {
  return t('payroll.runs.insurance.step_sentence', {
    base: money(step.input_minor_units),
    rate: percentage(step.rate.decimal),
    raw: money(step.output_minor_units),
    rounded: money(rounded ?? step.output_minor_units),
  })
}

function statusTone(status: string | undefined): string {
  if (status === 'error') return 'bg-danger-50 text-danger-700'
  if (status === 'calculated') return 'bg-success-50 text-success-700'
  return 'bg-warning-100 text-warning-800'
}

function issueLabel(code: string): string {
  return t('payroll.runs.insurance.issue', { code: participationReasonLabel(code) })
}

const PARTICIPATION_REASON_KEYS = new Set([
  'inactive_without_attributable_income',
  'income_month_attribution_unverified',
  'dpp_group_contains_unresolved_relationship',
  'dpp_group_threshold_met',
  'dpp_group_below_threshold',
  'regular_relationship',
  'agreed_income_threshold_met',
  'small_scale_group_contains_unresolved_relationship',
  'small_scale_group_threshold_met',
  'small_scale_group_below_threshold',
  'dpc_group_contains_unresolved_relationship',
  'dpc_group_negative_income_requires_period_revision',
  'dpc_group_threshold_met',
  'dpc_group_below_threshold',
  'dpp_group_negative_income_requires_period_revision',
  'dependent_income_relationship',
  'manual_review',
])

function participationReasonLabel(rawCode: string): string {
  const [rawKind, detail] = rawCode.split(':', 2)
  const kind = rawKind === 'regular-employment' ? 'regular_relationship' : rawKind
  if (kind === 'participation_component_manual_review') {
    return t('payroll.runs.insurance.reason.participation_component_manual_review', {
      component: detail || t('payroll.runs.insurance.unknown_component'),
    })
  }
  if (PARTICIPATION_REASON_KEYS.has(kind)) {
    return t(`payroll.runs.insurance.reason.${kind}`)
  }
  return t('payroll.runs.insurance.reason.unknown')
}

function participationReasonList(codes: string[]): string {
  return codes.map(participationReasonLabel).join('; ')
}

function componentList(codes: string[]): string {
  return codes.length ? codes.join(', ') : t('payroll.runs.insurance.none')
}

async function loadBreakdown(employeeId: number | null) {
  breakdown.value = null
  error.value = ''
  if (!available.value || employeeId === null || props.revisionId === null) return
  const sequence = ++requestSequence
  loading.value = true
  try {
    const loaded = await payrollInsuranceApi.breakdown(props.revisionId, employeeId)
    if (sequence !== requestSequence) return
    breakdown.value = loaded
  } catch (e: any) {
    if (sequence !== requestSequence) return
    error.value = e?.response?.data?.error?.message
      || t('payroll.runs.insurance.load_failed')
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

watch(insurancePeople, (people) => {
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
    data-testid="insurance-breakdown"
  >
    <div class="border-b border-payroll-100 bg-payroll-50/50 px-4 py-4 sm:px-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.insurance.title') }}</h3>
          <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.runs.insurance.subtitle') }}</p>
        </div>
        <button
          v-if="breakdown"
          type="button"
          :class="btnOutlineSm('neutral')"
          data-testid="insurance-relationships-toggle"
          @click="showRelationships = !showRelationships"
        >
          {{ showRelationships
            ? t('payroll.runs.insurance.relationships_hide')
            : t('payroll.runs.insurance.relationships_show') }}
        </button>
      </div>
    </div>

    <PayrollPersonPicker
      v-model="selectedEmployeeId"
      :options="personOptions"
      :selector-label="t('payroll.runs.insurance.people_tabs')"
    />

    <div v-if="loading" class="space-y-3 p-4 sm:p-5">
      <div v-for="index in 3" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <p
      v-else-if="error"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-testid="insurance-breakdown-error"
    >
      {{ error }}
    </p>

    <div v-else-if="breakdown" class="space-y-6 p-4 sm:p-5">
      <!-- SOCIÁLNÍ POJIŠTĚNÍ -->
      <section class="rounded-lg border border-neutral-200" data-testid="social-breakdown">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3">
          <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.insurance.social_title') }}</h4>
          <span
            v-if="social"
            class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="statusTone(social.status)"
            data-testid="social-status"
          >
            {{ t(`payroll.runs.insurance.status.${social.status}`) }}
          </span>
        </div>

        <p
          v-if="!social"
          class="p-4 text-sm text-neutral-600"
          data-testid="social-unavailable"
        >
          {{ t(`payroll.runs.insurance.unavailable.${breakdown.social.available
            ? 'result_set_missing'
            : breakdown.social.unavailable_reason}`) }}
        </p>

        <div v-else class="space-y-4 p-4">
          <p class="text-sm text-neutral-600">
            {{ t('payroll.runs.insurance.ruleset_note', {
              id: social.ruleset_id,
              date: social.calculation_date,
            }) }}
          </p>

          <dl class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.runs.insurance.participating_base') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-neutral-900">
                {{ money(social.assessment_base.participating_minor) }}
              </dd>
            </div>
            <div class="rounded-lg bg-payroll-50 p-3">
              <dt class="text-xs text-payroll-700">{{ t('payroll.runs.insurance.capped_base') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-payroll-800">
                {{ money(social.assessment_base.capped_minor) }}
              </dd>
            </div>
            <div class="rounded-lg bg-success-50 p-3">
              <dt class="text-xs text-success-700">{{ t('payroll.runs.insurance.employee_contribution') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-success-800">
                {{ moneyOrUnknown(social.employee.contribution_minor) }}
              </dd>
            </div>
          </dl>

          <p
            v-if="social.assessment_base.annual_maximum_applied"
            class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-900"
            data-testid="social-annual-maximum"
          >
            {{ t('payroll.runs.insurance.annual_maximum_applied', {
              ytd: money(social.assessment_base.year_to_date_before_month_minor),
              reduction: money(social.assessment_base.annual_maximum_reduction_minor),
              capped: money(social.assessment_base.capped_minor),
            }) }}
          </p>

          <dl class="space-y-2 text-sm">
            <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
              <dt class="text-neutral-600">{{ t('payroll.runs.insurance.employee_rate') }}</dt>
              <dd class="text-right font-medium tabular-nums" data-testid="social-employee-step">
                {{ social.employee.contribution_step
                  ? stepSentence(social.employee.contribution_step, social.employee.before_discount_minor)
                  : t('payroll.runs.insurance.step_not_recorded') }}
              </dd>
            </div>
            <div
              v-if="social.employee.discount_step"
              class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2"
            >
              <dt class="text-neutral-600">{{ t('payroll.runs.insurance.working_pensioner_discount') }}</dt>
              <dd class="text-right font-medium tabular-nums">
                − {{ money(social.employee.working_pensioner_discount_minor) }}
              </dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
              <dt class="font-medium text-neutral-800">{{ t('payroll.runs.insurance.employee_contribution') }}</dt>
              <dd class="font-semibold tabular-nums">
                {{ moneyOrUnknown(social.employee.contribution_minor) }}
              </dd>
            </div>
          </dl>

          <div class="rounded-lg bg-neutral-50 p-3" data-testid="social-employer">
            <p class="text-sm font-medium text-neutral-800">
              {{ t('payroll.runs.insurance.employer_title') }}
            </p>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.runs.insurance.employer_scope_note') }}</p>
            <dl class="mt-2 space-y-1 text-sm">
              <!--
                Sazby jsou podle § 7 odst. 1 tři, každá z vlastního vyměřovacího
                základu podle § 5a odst. 1. Má-li firma v měsíci jedinou
                kategorii, je to jedna věta jako dřív; jakmile jich má víc,
                musí být vidět všechny — jinak by účetní hledala, proč částka
                neodpovídá „té" sazbě. Prázdný rozpad má jen revize uložená
                dřív, než rozpad existoval.
              -->
              <div
                v-for="category in employerCategories"
                :key="category.category"
                class="flex flex-wrap justify-between gap-2"
                data-testid="social-employer-category"
              >
                <dt class="text-neutral-600">
                  {{ t(`payroll.runs.insurance.employer_rate_category.${category.category}`) }}
                </dt>
                <dd class="text-right font-medium tabular-nums">
                  {{ category.contribution_step
                    ? stepSentence(category.contribution_step, category.contribution_minor)
                    : t('payroll.runs.insurance.step_not_recorded') }}
                </dd>
              </div>
              <div v-if="employerCategories.length === 0" class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-600">{{ t('payroll.runs.insurance.employer_rate') }}</dt>
                <dd class="text-right font-medium tabular-nums">
                  {{ social.employer.contribution_step
                    ? stepSentence(social.employer.contribution_step, social.employer.contribution_before_discount_minor)
                    : t('payroll.runs.insurance.step_not_recorded') }}
                </dd>
              </div>
              <div
                v-if="social.employer.part_time_discount_minor"
                class="flex flex-wrap justify-between gap-2"
              >
                <dt class="text-neutral-600">{{ t('payroll.runs.insurance.part_time_discount') }}</dt>
                <dd class="text-right font-medium tabular-nums">
                  − {{ money(social.employer.part_time_discount_minor) }}
                </dd>
              </div>
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="font-medium text-neutral-800">{{ t('payroll.runs.insurance.employer_contribution') }}</dt>
                <dd class="font-semibold tabular-nums">
                  {{ moneyOrUnknown(social.employer.contribution_minor) }}
                </dd>
              </div>
            </dl>

            <!--
              Podíl osoby je ALOKACE firemní částky, ne zákonná osobní částka.
              Proto má vlastní rámeček, vlastní popisek a větu o metodě — vypsat
              ho mezi ostatní částky by z něj udělal zákonný údaj.
            -->
            <div
              v-if="employerAllocation"
              class="mt-3 rounded-lg border border-dashed border-neutral-300 bg-surface p-3"
              data-testid="social-employer-allocation"
            >
              <p class="text-sm font-medium text-neutral-800">
                {{ t('payroll.runs.insurance.allocation_title') }}
              </p>
              <template v-if="employerAllocation.method === 'not_allocatable'">
                <p class="mt-1 text-sm text-warning-900" data-testid="allocation-blocked">
                  {{ t(`payroll.runs.insurance.allocation_blocker.${employerAllocation.not_allocatable_reason}`) }}
                </p>
              </template>
              <template v-else>
                <p class="mt-1 text-lg font-semibold tabular-nums text-neutral-900">
                  {{ moneyOrUnknown(employerAllocation.person_minor) }}
                </p>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.runs.insurance.allocation_note', {
                    method: t(`payroll.runs.insurance.allocation_method.${employerAllocation.method}`),
                    personBase: money(employerAllocation.person_assessment_base_minor),
                    companyBase: money(employerAllocation.company_assessment_base_minor),
                    people: employerAllocation.people_count,
                  }) }}
                </p>
              </template>
            </div>
          </div>

          <div v-if="showRelationships && social.relationships.length" class="space-y-2">
            <article
              v-for="relationship in social.relationships"
              :key="relationship.employment_id"
              class="rounded-lg bg-neutral-50 p-3 text-sm"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-medium text-neutral-900">
                  {{ t(`payroll.runs.insurance.relationship_kind.${relationship.kind}`) }}
                  <span class="text-xs font-normal text-neutral-500">{{ relationship.relationship_reference }}</span>
                </p>
                <span class="rounded-full bg-surface px-2.5 py-1 text-xs font-medium text-payroll-700">
                  {{ t(`payroll.runs.insurance.participation.${relationship.participation_status}`) }}
                </span>
              </div>
              <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.relationship_base') }}</dt>
                  <dd class="font-medium tabular-nums">{{ money(relationship.assessment_base_minor) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.relationship_capped_base') }}</dt>
                  <dd class="font-medium tabular-nums">{{ money(relationship.capped_assessment_base_minor) }}</dd>
                </div>
                <div v-if="relationship.threshold_minor !== null" class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.threshold') }}</dt>
                  <dd class="font-medium tabular-nums">{{ money(relationship.threshold_minor) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.excluded_components') }}</dt>
                  <dd class="break-words text-right font-medium">
                    {{ componentList(relationship.excluded_assessment_base_components) }}
                  </dd>
                </div>
              </dl>
              <p v-if="relationship.reason_codes.length" class="mt-2 text-xs text-neutral-500">
                {{ t('payroll.runs.insurance.reasons', { codes: participationReasonList(relationship.reason_codes) }) }}
              </p>
            </article>
          </div>

          <ul
            v-if="social.issues.length"
            class="list-disc space-y-1 rounded-lg border border-warning-200 bg-warning-50 p-3 pl-8 text-sm text-warning-900"
            data-testid="social-issues"
          >
            <li v-for="issue in social.issues" :key="issue">{{ issueLabel(issue) }}</li>
          </ul>
        </div>
      </section>

      <!-- ZDRAVOTNÍ POJIŠTĚNÍ -->
      <section class="rounded-lg border border-neutral-200" data-testid="health-breakdown">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3">
          <h4 class="font-medium text-neutral-900">{{ t('payroll.runs.insurance.health_title') }}</h4>
          <span
            v-if="health"
            class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="statusTone(health.status)"
            data-testid="health-status"
          >
            {{ t(`payroll.runs.insurance.status.${health.status}`) }}
          </span>
        </div>

        <p
          v-if="!health"
          class="p-4 text-sm text-neutral-600"
          data-testid="health-unavailable"
        >
          {{ t(`payroll.runs.insurance.unavailable.${breakdown.health.available
            ? 'result_set_missing'
            : breakdown.health.unavailable_reason}`) }}
        </p>

        <div v-else class="space-y-4 p-4">
          <p class="text-sm text-neutral-600">
            {{ t('payroll.runs.insurance.ruleset_note', {
              id: health.ruleset_id,
              date: health.calculation_date,
            }) }}
          </p>

          <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.runs.insurance.health_base') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-neutral-900">
                {{ money(health.assessment_base.this_employer_minor) }}
              </dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.runs.insurance.health_minimum') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-neutral-900">
                {{ money(health.minimum.effective_minor) }}
              </dd>
            </div>
            <div class="rounded-lg bg-payroll-50 p-3">
              <dt class="text-xs text-payroll-700">{{ t('payroll.runs.insurance.employee_contribution') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-payroll-800">
                {{ moneyOrUnknown(health.contribution.employee_minor) }}
              </dd>
            </div>
            <div class="rounded-lg bg-success-50 p-3">
              <dt class="text-xs text-success-700">{{ t('payroll.runs.insurance.employer_contribution') }}</dt>
              <dd class="mt-1 font-semibold tabular-nums text-success-800">
                {{ moneyOrUnknown(health.contribution.employer_minor) }}
              </dd>
            </div>
          </dl>

          <!--
            Rekonstruovaná sazba se NESMÍ tvářit jako uložená: věta stojí NAD
            rozkladem, který z ní vychází, a jmenuje sadu pravidel, ze které je
            doložená. Rozklad samotný se zobrazí normálně — je dokázaný shodou
            s uloženou částkou.
          -->
          <p
            v-if="healthRateReconstructed && health.contribution.rate_reconstruction"
            class="rounded-lg border border-payroll-200 bg-surface p-3 text-sm text-neutral-700"
            data-testid="health-rate-reconstructed"
          >
            {{ t('payroll.runs.insurance.health_rate_reconstructed', {
              ruleset: health.contribution.rate_reconstruction.ruleset_id,
              version: health.contribution.rate_reconstruction.ruleset_version,
            }) }}
          </p>

          <p
            v-if="healthRateMissing"
            class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-900"
            data-testid="health-rate-missing"
          >
            {{ t('payroll.runs.insurance.health_rate_not_recorded') }}
          </p>

          <p
            v-else-if="healthNotApplicable"
            class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700"
            data-testid="health-not-applicable"
          >
            {{ t('payroll.runs.insurance.health_no_contribution') }}
          </p>

          <dl v-else class="space-y-2 text-sm">
            <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
              <dt class="text-neutral-600">{{ t('payroll.runs.insurance.health_total_rate') }}</dt>
              <dd class="text-right font-medium tabular-nums" data-testid="health-standard-step">
                {{ health.contribution.standard_step
                  ? stepSentence(health.contribution.standard_step, health.contribution.standard_minor)
                  : t('payroll.runs.insurance.step_not_recorded') }}
              </dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2">
              <dt class="text-neutral-600">{{ t('payroll.runs.insurance.health_employee_share') }}</dt>
              <dd class="text-right font-medium tabular-nums">
                {{ moneyOrUnknown(health.contribution.employee_standard_minor) }}
              </dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
              <dt class="text-neutral-600">{{ t('payroll.runs.insurance.health_employer_share') }}</dt>
              <dd class="text-right font-medium tabular-nums">
                {{ moneyOrUnknown(health.contribution.employer_standard_minor) }}
              </dd>
            </div>
          </dl>

          <div
            v-if="health.minimum.top_up_applied"
            class="rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-payroll-900"
            data-testid="health-minimum-top-up"
          >
            <p class="font-medium">{{ t('payroll.runs.insurance.health_top_up_title') }}</p>
            <p v-if="health.minimum.top_up_base_minor !== null" class="mt-1">
              {{ t('payroll.runs.insurance.health_top_up_detail', {
                base: money(health.assessment_base.this_employer_minor),
                minimum: money(health.minimum.effective_minor),
                difference: money(health.minimum.top_up_base_minor),
                amount: money((health.contribution.employee_top_up_minor ?? 0)
                  + (health.contribution.employer_top_up_minor ?? 0)),
              }) }}
            </p>
            <!-- Základ dopočtu revize neuložila. Nula by tvrdila, že žádný nebyl. -->
            <p v-else class="mt-1" data-testid="health-top-up-base-unknown">
              {{ t('payroll.runs.insurance.health_top_up_amount_only', {
                minimum: money(health.minimum.effective_minor),
                amount: money((health.contribution.employee_top_up_minor ?? 0)
                  + (health.contribution.employer_top_up_minor ?? 0)),
              }) }}
            </p>
            <!--
              Kdo doplatek hradí, a jestli to někdo prohlásil, nebo se to
              odvodilo ze zákona. Bez toho rozlišení nejde po letech poznat,
              čím byla schválená mzda podložená. Prázdný původ = revize
              spočítaná dřív, než klíč vznikl; tam se nedomýšlí nic.
            -->
            <p class="mt-1 text-xs">
              {{ t(`payroll.runs.insurance.top_up_responsibility.${health.minimum.top_up_responsibility}`) }}
              <span
                v-if="health.minimum.top_up_responsibility_source"
                class="text-neutral-500"
                data-testid="health-top-up-source"
              >
                · {{ t(`payroll.runs.insurance.top_up_responsibility_source.${health.minimum.top_up_responsibility_source}`) }}
              </span>
            </p>
            <p v-if="health.minimum.applicable_calendar_days !== health.minimum.employment_calendar_days" class="mt-1 text-xs">
              {{ t('payroll.runs.insurance.health_minimum_days', {
                applicable: health.minimum.applicable_calendar_days,
                excluded: health.minimum.excluded_calendar_days,
                statutory: money(health.minimum.statutory_monthly_minor),
              }) }}
            </p>
          </div>

          <div
            v-if="health.assessment_base.other_employers_minor"
            class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700"
            data-testid="health-other-employers"
          >
            {{ t('payroll.runs.insurance.health_other_employers', {
              other: money(health.assessment_base.other_employers_minor),
              combined: money(health.assessment_base.combined_minor),
            }) }}
          </div>

          <section v-if="health.insurer_liabilities.length" data-testid="health-insurers">
            <h5 class="text-sm font-medium text-neutral-900">
              {{ t('payroll.runs.insurance.insurer_split_title') }}
            </h5>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.runs.insurance.insurer_split_hint') }}</p>
            <div class="mt-2 overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                  <tr>
                    <th class="px-3 py-2">{{ t('payroll.runs.insurance.insurer') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll.runs.insurance.insurer_people') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll.runs.insurance.employee_contribution') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll.runs.insurance.employer_contribution') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll.runs.insurance.insurer_total') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr
                    v-for="liability in health.insurer_liabilities"
                    :key="liability.insurer_code"
                    :class="liability.is_person_insurer ? 'bg-payroll-50/60 font-medium' : ''"
                    :data-testid="`insurer-${liability.insurer_code}`"
                  >
                    <td class="px-3 py-2">
                      {{ liability.insurer_code }}
                      <span v-if="liability.is_person_insurer" class="ml-1 text-xs text-payroll-700">
                        {{ t('payroll.runs.insurance.insurer_of_person') }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ liability.person_count }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(liability.employee_minor) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(liability.employer_minor) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(liability.total_minor) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <div v-if="showRelationships && health.relationships.length" class="space-y-2">
            <article
              v-for="relationship in health.relationships"
              :key="relationship.employment_id"
              class="rounded-lg bg-neutral-50 p-3 text-sm"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-medium text-neutral-900">
                  {{ t(`payroll.runs.insurance.relationship_kind.${relationship.kind}`) }}
                  <span class="text-xs font-normal text-neutral-500">{{ relationship.relationship_reference }}</span>
                </p>
                <span class="rounded-full bg-surface px-2.5 py-1 text-xs font-medium text-payroll-700">
                  {{ t(`payroll.runs.insurance.participation.${relationship.participation_status}`) }}
                </span>
              </div>
              <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.relationship_base') }}</dt>
                  <dd class="font-medium tabular-nums">{{ money(relationship.assessment_base_minor) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.relationship_participating_base') }}</dt>
                  <dd class="font-medium tabular-nums">
                    {{ money(relationship.participating_assessment_base_minor) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('payroll.runs.insurance.excluded_components') }}</dt>
                  <dd class="break-words text-right font-medium">
                    {{ componentList(relationship.excluded_assessment_base_components) }}
                  </dd>
                </div>
              </dl>
              <p v-if="relationship.reason_codes.length" class="mt-2 text-xs text-neutral-500">
                {{ t('payroll.runs.insurance.reasons', { codes: participationReasonList(relationship.reason_codes) }) }}
              </p>
            </article>
          </div>

          <ul
            v-if="health.issues.length"
            class="list-disc space-y-1 rounded-lg border border-warning-200 bg-warning-50 p-3 pl-8 text-sm text-warning-900"
            data-testid="health-issues"
          >
            <li v-for="issue in health.issues" :key="issue">{{ issueLabel(issue) }}</li>
          </ul>
        </div>
      </section>
    </div>
  </section>
</template>

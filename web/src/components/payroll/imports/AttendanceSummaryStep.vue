<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  AttendanceComponentCheck,
  AttendanceConflict,
  AttendanceEmploymentOption,
  AttendancePerson,
  AttendancePreview,
} from '@/api/payrollImports'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import { previewRefreshRuns } from './attendanceWages'
import {
  effectiveEmploymentId,
  formatHours,
  personHasConflicts,
  summaryComponents,
  summaryDeductions,
  summaryMeanings,
  type ManualLinks,
} from './importHelpers'

const props = defineProps<{
  preview: AttendancePreview
  manualLinks: ManualLinks
  /** Osoby bez vztahu, které použití samo založí; do součtů patří. */
  createKeys?: string[]
}>()

const { t, te, locale } = useI18n()

const onlyIssues = ref(false)

const meanings = computed(() => summaryMeanings(props.preview.persons))
const components = computed(() => summaryComponents(props.preview.persons))
const deductionMeanings = computed(() => summaryDeductions(props.preview.persons))
const optionById = computed(() => new Map(props.preview.employment_options.map(option => [option.employment_id, option])))
const hasWorkedDays = computed(() => props.preview.persons.some(person => typeof person.worked_days === 'number'))
const hasReference = computed(() => props.preview.persons.some(person =>
  person.reference.gross_minor !== null || person.reference.net_minor !== null || person.reference.hours !== null))
const createKeySet = computed(() => new Set(props.createKeys ?? []))
function isIncluded(person: AttendancePerson): boolean {
  return employmentOf(person) !== null || createKeySet.value.has(person.key)
}
const includedPersons = computed(() => props.preview.persons.filter(isIncluded))
const skippedCount = computed(() => props.preview.persons.length - includedPersons.value.length)
function componentsMinor(persons: AttendancePerson[]): number {
  return persons.reduce((sum, person) => sum + person.components.reduce((total, item) => total + item.amount_minor, 0), 0)
}
const includedAmountMinor = computed(() => componentsMinor(includedPersons.value))
const skippedAmountMinor = computed(() => componentsMinor(props.preview.persons) - includedAmountMinor.value)
const conflictCount = computed(() => props.preview.persons.filter(personHasConflicts).length)
const wageChanges = computed(() => props.preview.wage_changes ?? [])
const rateWages = computed(() => props.preview.summary.rate_wages ?? { computed: 0, amount_minor: 0, no_hours: 0, hourly_terms: 0 })
// Přeskočená osoba vstup nedostane, do počtu a částky mzdy ze sazby nepatří.
const includedRateWages = computed(() => {
  let count = 0
  let amount = 0
  for (const person of includedPersons.value) {
    const wage = person.rate_wage
    if (!wage || wage.status !== 'computed') continue
    count++
    amount += wage.amount_minor
  }
  return { count, amount }
})

/** „30,50 h × 200,00 Kč" u mzdy dopočtené ze sazby a hodin. */
function rateWageFormula(person: AttendancePerson, code: string): string | null {
  const wage = person.rate_wage
  if (!wage || wage.status !== 'computed' || wage.component_code !== code) return null
  return t('payroll_imports.attendance.summary.rate_wage_formula', {
    hours: formatHours(wage.hours, locale.value),
    rate: formatMoneyMinor(wage.hourly_rate_minor),
  })
}
const wageRefreshPeriods = computed(() => previewRefreshRuns(wageChanges.value).map(run => formatPeriod(run.period)).join(', '))

function wageBadgeClass(change: { reason: string | null, mode: string }): string {
  if (change.reason !== null) return 'bg-warning-50 text-warning-700'
  return change.mode === 'add' ? 'bg-primary-50 text-primary-700' : 'bg-success-50 text-success-700'
}

function wageBadgeLabel(change: { reason: string | null, mode: string }): string {
  if (change.reason !== null) return t('payroll_imports.attendance.summary.wages_blocked')
  const key = `payroll_imports.attendance.summary.wages_mode.${change.mode}`
  return te(key) ? t(key) : change.mode
}

function currentWage(minor: number | null): string {
  return minor === null ? t('payroll_imports.attendance.summary.wages_missing') : formatMoneyMinor(minor)
}

const visiblePersons = computed(() => onlyIssues.value
  ? props.preview.persons.filter(person => personHasConflicts(person) || person.warnings.length > 0 || (person.errors?.length ?? 0) > 0 || employmentOf(person) === null)
  : props.preview.persons)

function employmentOf(person: AttendancePerson): AttendanceEmploymentOption | null {
  const id = effectiveEmploymentId(person, props.manualLinks)
  return id === null ? null : optionById.value.get(id) ?? null
}

function meaningLabel(meaning: string): string {
  const key = `payroll_imports.meanings.${meaning}`
  return te(key) ? t(key) : meaning
}

function kindLabel(kind: string | null): string {
  if (!kind) return ''
  const key = `payroll_imports.component_kinds.${kind}`
  return te(key) ? t(key) : kind
}

function metric(person: AttendancePerson, meaning: string) {
  return person.metrics.find(item => item.meaning === meaning) ?? null
}

function component(person: AttendancePerson, code: string) {
  return person.components.find(item => item.component_code === code) ?? null
}

function deduction(person: AttendancePerson, meaning: string) {
  return (person.deductions ?? []).find(item => item.meaning === meaning) ?? null
}

function conflictTitle(source: string, conflicts: AttendanceConflict[]): string {
  const lines = [t('payroll_imports.attendance.summary.used_source', { source })]
  for (const conflict of conflicts) {
    const value = conflict.hours ?? conflict.amount ?? (conflict.amount_minor !== null && conflict.amount_minor !== undefined
      ? formatMoneyMinor(conflict.amount_minor)
      : '—')
    lines.push(t('payroll_imports.attendance.summary.conflict_line', { value, source: conflict.source }))
  }
  return lines.join('\n')
}

function checkClass(check: AttendanceComponentCheck): string {
  return {
    ok: 'bg-success-50 text-success-700',
    missing: 'bg-danger-50 text-danger-600',
    not_one_off: 'bg-warning-50 text-warning-700',
    will_create: 'bg-primary-50 text-primary-700',
  }[check.status]
}
</script>

<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.summary.persons') }}</p><p class="mt-1 text-lg font-semibold">{{ preview.summary.persons }}</p></article>
      <article class="rounded-lg bg-success-50 p-3"><p class="text-xs text-success-700">{{ t('payroll_imports.attendance.summary.linked') }}</p><p class="mt-1 text-lg font-semibold text-success-700">{{ preview.persons.length - skippedCount }}</p></article>
      <article class="rounded-lg p-3" :class="skippedCount ? 'bg-warning-50' : 'bg-neutral-50'"><p class="text-xs" :class="skippedCount ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll_imports.attendance.summary.skipped') }}</p><p class="mt-1 text-lg font-semibold" :class="skippedCount ? 'text-warning-700' : ''">{{ skippedCount }}</p></article>
      <article class="rounded-lg p-3" :class="conflictCount ? 'bg-warning-50' : 'bg-neutral-50'"><p class="text-xs" :class="conflictCount ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll_imports.attendance.summary.conflicts') }}</p><p class="mt-1 text-lg font-semibold" :class="conflictCount ? 'text-warning-700' : ''">{{ conflictCount }}</p></article>
      <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.summary.metrics') }}</p><p class="mt-1 text-lg font-semibold">{{ preview.summary.metrics }}</p></article>
      <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.summary.amount_total') }}</p><p class="mt-1 text-lg font-semibold" data-testid="attendance-amount-total">{{ formatMoneyMinor(includedAmountMinor) }}</p><p v-if="skippedAmountMinor > 0" class="mt-0.5 text-xs text-warning-700" data-testid="attendance-amount-skipped">{{ t('payroll_imports.attendance.summary.amount_skipped', { amount: formatMoneyMinor(skippedAmountMinor) }) }}</p></article>
    </div>

    <!-- Podklady jen s hodinami a sazbou: bez tohohle by souhrn ukázal 0 Kč mezd a nikdo by nevěděl proč. -->
    <section v-if="rateWages.computed || rateWages.no_hours || rateWages.hourly_terms" class="rounded-xl border p-4 text-sm shadow-sm"
      :class="rateWages.no_hours || rateWages.hourly_terms ? 'border-warning-500/40 bg-warning-50' : 'border-primary-500/30 bg-primary-50'"
      data-testid="attendance-rate-wages">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.rate_wages_title') }}</h3>
      <p v-if="rateWages.computed" class="mt-1 text-neutral-700" data-testid="attendance-rate-wages-computed">
        {{ t('payroll_imports.attendance.summary.rate_wages_computed', { count: includedRateWages.count, amount: formatMoneyMinor(includedRateWages.amount) }) }}
        <template v-if="includedRateWages.count < rateWages.computed">{{ t('payroll_imports.attendance.summary.rate_wages_skipped', { count: rateWages.computed - includedRateWages.count }) }}</template>
      </p>
      <p v-if="rateWages.no_hours" class="mt-1 font-medium text-warning-700" data-testid="attendance-rate-wages-no-hours">
        {{ t('payroll_imports.attendance.summary.rate_wages_no_hours', { count: rateWages.no_hours }) }}
      </p>
      <p v-if="rateWages.hourly_terms" class="mt-1 font-medium text-warning-700" data-testid="attendance-rate-wages-hourly-terms">
        {{ t('payroll_imports.attendance.summary.rate_wages_hourly_terms', { count: rateWages.hourly_terms }) }}
      </p>
    </section>

    <section v-if="preview.component_checks.length" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.component_checks') }}</h3>
      <ul class="mt-2 space-y-1.5">
        <li v-for="check in preview.component_checks" :key="check.component_code" class="flex flex-wrap items-center gap-2 text-sm">
          <span class="font-mono text-xs font-medium text-neutral-800">{{ check.component_code }}</span>
          <span v-if="check.name" class="text-xs text-neutral-700">{{ check.name }}<template v-if="check.kind"> ({{ kindLabel(check.kind) }})</template></span>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="checkClass(check)">{{ t(`payroll_imports.attendance.summary.check_status.${check.status}`) }}</span>
          <span v-if="check.message" class="text-xs text-neutral-600">{{ check.message }}</span>
        </li>
      </ul>
    </section>

    <!--
      Měsíční mzda z podkladů proti sjednaným podmínkám. Řádek s důvodem se
      nepřevezme a důvod musí být vidět u člověka, ne až ve výsledku.
    -->
    <section v-if="wageChanges.length" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm" data-testid="attendance-wage-changes">
      <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.wages_title') }}</h3>
      <p class="mt-1 max-w-3xl text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.wages_hint') }}</p>
      <div class="mt-3 hidden max-h-[50vh] overflow-auto md:block">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-2 py-1.5">{{ t('payroll_imports.attendance.summary.wages_person') }}</th>
              <th class="px-2 py-1.5 text-right">{{ t('payroll_imports.attendance.summary.wages_current') }}</th>
              <th class="px-2 py-1.5 text-right">{{ t('payroll_imports.attendance.summary.wages_imported') }}</th>
              <th class="px-2 py-1.5">{{ t('payroll_imports.attendance.summary.wages_mode_label') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="change in wageChanges" :key="change.key" class="border-t border-neutral-100 align-top" :class="change.reason !== null ? 'bg-warning-50/60' : ''" data-testid="attendance-wage-row">
              <td class="px-2 py-1.5">
                <p class="font-medium text-neutral-900">{{ change.display_name }}</p>
                <p v-if="change.reason" class="mt-0.5 max-w-md text-xs text-warning-700">{{ change.reason }}</p>
              </td>
              <td class="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-neutral-600">{{ currentWage(change.current_minor) }}</td>
              <td class="whitespace-nowrap px-2 py-1.5 text-right font-medium tabular-nums text-neutral-900">{{ formatMoneyMinor(change.imported_minor) }}</td>
              <td class="px-2 py-1.5"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="wageBadgeClass(change)">{{ wageBadgeLabel(change) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mt-3 space-y-2 md:hidden">
        <article v-for="change in wageChanges" :key="change.key" class="rounded-lg p-3 text-sm" :class="change.reason !== null ? 'bg-warning-50' : 'bg-neutral-50'">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-medium text-neutral-900">{{ change.display_name }}</p>
            <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="wageBadgeClass(change)">{{ wageBadgeLabel(change) }}</span>
          </div>
          <p class="mt-1 tabular-nums text-neutral-700">{{ currentWage(change.current_minor) }} → <span class="font-medium text-neutral-900">{{ formatMoneyMinor(change.imported_minor) }}</span></p>
          <p v-if="change.reason" class="mt-1 text-xs text-warning-700">{{ change.reason }}</p>
        </article>
      </div>
      <p v-if="wageRefreshPeriods" class="mt-3 text-xs text-neutral-600" data-testid="attendance-wage-runs">{{ t('payroll_imports.attendance.summary.wages_runs', { periods: wageRefreshPeriods }) }}</p>
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-4 py-3">
        <p class="text-sm text-neutral-600">{{ t('payroll_imports.attendance.summary.table_hint') }}</p>
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="onlyIssues" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll_imports.attendance.summary.only_issues') }}
        </label>
      </div>

      <p v-if="visiblePersons.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.attendance.summary.empty') }}</p>

      <div v-else class="hidden max-h-[70vh] overflow-auto md:block" data-testid="attendance-summary-table">
        <table class="min-w-full border-separate border-spacing-0 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="sticky left-0 top-0 z-20 min-w-52 border-b border-neutral-200 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.summary.person') }}</th>
              <th v-if="hasWorkedDays" class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case">{{ meaningLabel('worked_days') }}</th>
              <th v-for="meaning in meanings" :key="meaning" class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case" :title="meaning">{{ meaningLabel(meaning) }}</th>
              <th v-for="code in components" :key="`c-${code}`" class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right font-mono normal-case">{{ code }}</th>
              <th v-for="meaning in deductionMeanings" :key="`d-${meaning}`" class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case">{{ meaningLabel(meaning) }}</th>
              <template v-if="hasReference">
                <th class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case">{{ t('payroll_imports.attendance.summary.reference_gross') }}</th>
                <th class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case">{{ t('payroll_imports.attendance.summary.reference_net') }}</th>
                <th class="sticky top-0 z-10 border-b border-neutral-200 bg-surface px-3 py-2 text-right normal-case">{{ t('payroll_imports.attendance.summary.reference_hours') }}</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="person in visiblePersons" :key="person.key" class="align-top">
              <td class="sticky left-0 z-[5] border-b border-neutral-100 bg-surface px-3 py-2">
                <p class="font-medium text-neutral-900">{{ person.display_name }}</p>
                <p v-if="employmentOf(person)" class="text-xs text-neutral-500">{{ employmentOf(person)?.label }}</p>
                <span v-else class="mt-0.5 inline-block rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-medium text-warning-700">{{ t('payroll_imports.attendance.summary.will_skip') }}</span>
                <ul v-if="person.errors?.length" class="mt-1 max-w-xs space-y-0.5 text-[11px] font-medium text-danger-600">
                  <li v-for="error in person.errors" :key="error">{{ error }}</li>
                </ul>
                <ul v-if="person.warnings.length" class="mt-1 max-w-xs space-y-0.5 text-[11px] text-warning-700">
                  <li v-for="warning in person.warnings" :key="warning">{{ warning }}</li>
                </ul>
              </td>
              <td v-if="hasWorkedDays" class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums text-neutral-800" data-testid="attendance-worked-days">{{ person.worked_days ?? '' }}</td>
              <td v-for="meaning in meanings" :key="meaning" class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums"
                :class="metric(person, meaning)?.conflicts.length ? 'bg-warning-50 font-medium text-warning-700' : 'text-neutral-800'"
                :title="metric(person, meaning) ? conflictTitle(metric(person, meaning)!.source, metric(person, meaning)!.conflicts) : undefined">
                {{ metric(person, meaning) ? formatHours(metric(person, meaning)!.hours, locale) : '' }}
                <span v-if="metric(person, meaning)?.conflicts.length" aria-hidden="true">⚠</span>
              </td>
              <td v-for="code in components" :key="`c-${code}`" class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums"
                :class="component(person, code)?.conflicts.length ? 'bg-warning-50 font-medium text-warning-700' : 'text-neutral-800'"
                :title="component(person, code) ? conflictTitle(component(person, code)!.source, component(person, code)!.conflicts) : undefined">
                {{ component(person, code) ? formatMoneyMinor(component(person, code)!.amount_minor) : '' }}
                <span v-if="component(person, code)?.conflicts.length" aria-hidden="true">⚠</span>
                <span v-if="rateWageFormula(person, code)" class="block text-[11px] text-neutral-500" data-testid="attendance-rate-wage-formula">{{ rateWageFormula(person, code) }}</span>
                <span v-if="component(person, code)?.rounded_from_minor !== undefined" class="block text-[11px] text-neutral-500" data-testid="attendance-rounded-from">{{ t('payroll_imports.attendance.summary.rounded_from', { amount: formatMoneyMinor(component(person, code)!.rounded_from_minor!) }) }}</span>
              </td>
              <td v-for="meaning in deductionMeanings" :key="`d-${meaning}`" class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums"
                :class="deduction(person, meaning)?.conflicts.length ? 'bg-warning-50 font-medium text-warning-700' : 'text-neutral-800'"
                :title="deduction(person, meaning) ? conflictTitle(deduction(person, meaning)!.source, deduction(person, meaning)!.conflicts) : undefined">
                {{ deduction(person, meaning) ? `−${formatMoneyMinor(deduction(person, meaning)!.amount_minor)}` : '' }}
                <span v-if="deduction(person, meaning)?.conflicts.length" aria-hidden="true">⚠</span>
              </td>
              <template v-if="hasReference">
                <td class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums text-neutral-500">{{ person.reference.gross_minor !== null ? formatMoneyMinor(person.reference.gross_minor) : '' }}</td>
                <td class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums text-neutral-500">{{ person.reference.net_minor !== null ? formatMoneyMinor(person.reference.net_minor) : '' }}</td>
                <td class="whitespace-nowrap border-b border-neutral-100 px-3 py-2 text-right tabular-nums text-neutral-500">{{ person.reference.hours !== null ? formatHours(person.reference.hours, locale) : '' }}</td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="visiblePersons.length" class="space-y-2 p-3 md:hidden">
        <article v-for="person in visiblePersons" :key="person.key" class="rounded-lg bg-neutral-50 p-3 text-sm">
          <p class="font-medium text-neutral-900">{{ person.display_name }}</p>
          <p v-if="employmentOf(person)" class="text-xs text-neutral-500">{{ employmentOf(person)?.label }}</p>
          <span v-else class="mt-0.5 inline-block rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-medium text-warning-700">{{ t('payroll_imports.attendance.summary.will_skip') }}</span>
          <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
            <template v-if="typeof person.worked_days === 'number'">
              <dt class="text-neutral-500">{{ meaningLabel('worked_days') }}</dt>
              <dd class="text-right tabular-nums text-neutral-800">{{ person.worked_days }}</dd>
            </template>
            <template v-for="item in person.metrics" :key="item.meaning">
              <dt class="text-neutral-500">{{ meaningLabel(item.meaning) }}</dt>
              <dd class="text-right tabular-nums" :class="item.conflicts.length ? 'font-medium text-warning-700' : 'text-neutral-800'">{{ formatHours(item.hours, locale) }}<span v-if="item.conflicts.length"> ⚠</span></dd>
            </template>
            <template v-for="item in person.components" :key="`c-${item.component_code}`">
              <dt class="font-mono text-neutral-500">{{ item.component_code }}</dt>
              <dd class="text-right tabular-nums" :class="item.conflicts.length ? 'font-medium text-warning-700' : 'text-neutral-800'">{{ formatMoneyMinor(item.amount_minor) }}<span v-if="item.conflicts.length"> ⚠</span><span v-if="rateWageFormula(person, item.component_code)" class="block text-[11px] text-neutral-500">{{ rateWageFormula(person, item.component_code) }}</span><span v-if="item.rounded_from_minor !== undefined" class="block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.summary.rounded_from', { amount: formatMoneyMinor(item.rounded_from_minor) }) }}</span></dd>
            </template>
            <template v-for="item in person.deductions ?? []" :key="`d-${item.meaning}`">
              <dt class="text-neutral-500">{{ meaningLabel(item.meaning) }}</dt>
              <dd class="text-right tabular-nums" :class="item.conflicts.length ? 'font-medium text-warning-700' : 'text-neutral-800'">−{{ formatMoneyMinor(item.amount_minor) }}<span v-if="item.conflicts.length"> ⚠</span></dd>
            </template>
          </dl>
          <ul v-if="person.errors?.length" class="mt-2 space-y-0.5 text-xs font-medium text-danger-600">
            <li v-for="error in person.errors" :key="error">{{ error }}</li>
          </ul>
          <ul v-if="person.warnings.length" class="mt-2 space-y-0.5 text-xs text-warning-700">
            <li v-for="warning in person.warnings" :key="warning">{{ warning }}</li>
          </ul>
        </article>
      </div>
    </section>
  </div>
</template>

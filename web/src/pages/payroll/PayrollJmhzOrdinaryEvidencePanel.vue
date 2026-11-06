<script setup lang="ts">
import { computed, markRaw, ref, watch } from 'vue'
import { jmhzEvidenceGuidance } from './jmhzEvidenceGuidance'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzOrdinaryEvidenceScope,
  type PayrollJmhzOzpWage,
  type PayrollRun,
} from '@/api/payroll'
import ExpandableList from '@/components/ui/ExpandableList.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatPeriod } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ runs: PayrollRun[] }>()
const { t } = useI18n()
const auth = useAuthStore()
// Potvrzení výdělku OZP zapisuje (POST jmhz-ordinary-evidence vyžaduje zápis podání).
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

interface EvidenceState {
  loading: boolean
  error: string
  failure?: { code: string, technical: string }
  scopes: PayrollJmhzOrdinaryEvidenceScope[]
  /** Potvrzené výdělky OZP revize (10347 až 10353) k přehledu. */
  ozpWages?: Array<{ employment_id: number, wage: PayrollJmhzOzpWage }>
}

type Guidance = ReturnType<typeof jmhzEvidenceGuidance>

interface AttentionEntry {
  scope: PayrollJmhzOrdinaryEvidenceScope
  guidance: Guidance
}

interface AttentionGroup {
  key: string
  guidance: Guidance
  /** Všechny vztahy skupiny vedou na totéž místo — odkaz stačí jednou. */
  sharedAction: boolean
  entries: AttentionEntry[]
}

interface EvidenceView {
  automatic: number
  confirmed: number
  attention: number
  groups: AttentionGroup[]
}

const states = ref<Record<number, EvidenceState>>({})

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function state(run: PayrollRun): EvidenceState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

/**
 * Souhrn revize se počítá jednou za načtení, ne v šabloně.
 *
 * Firma s 226 vztahy zamrazila prohlížeč: šablona filtrovala vztahy několikrát
 * za překreslení a návod k nápravě počítala osmkrát na každou kartu. Stejný
 * problém u stovek lidí je navíc jedna věc k vyřízení, ne stovky karet, proto
 * se vztahy seskupí podle návodu a vypíšou se sbaleně po stránkách.
 */
function buildView(scopes: PayrollJmhzOrdinaryEvidenceScope[], period: string): EvidenceView {
  let automatic = 0
  let confirmed = 0
  let attention = 0
  const groups = new Map<string, AttentionGroup>()
  for (const scope of scopes) {
    if (scope.resolution === 'automatic_on_preparation') {
      automatic++
      continue
    }
    if (scope.resolution === 'confirmed') {
      confirmed++
      continue
    }
    if (scope.resolution !== 'attention_required') continue
    attention++
    const guidance = jmhzEvidenceGuidance(scope, period)
    const key = JSON.stringify([
      guidance.problemKey, guidance.fieldKey, guidance.stepKey, guidance.actionKey, guidance.versions,
    ])
    const group = groups.get(key)
    if (group === undefined) {
      groups.set(key, { key, guidance, sharedAction: true, entries: [{ scope, guidance }] })
      continue
    }
    group.entries.push({ scope, guidance })
    if (guidance.path !== group.guidance.path || guidance.agreementsPath !== group.guidance.agreementsPath) {
      group.sharedAction = false
    }
  }
  return { automatic, confirmed, attention, groups: [...groups.values()] }
}

const views = computed(() => {
  const result: Record<number, EvidenceView> = {}
  for (const run of props.runs) {
    const id = revisionId(run)
    const current = id === null ? undefined : states.value[id]
    if (id !== null && current !== undefined && !current.loading) {
      result[id] = buildView(current.scopes, run.period_start)
    }
  }
  return result
})

function view(run: PayrollRun): EvidenceView | null {
  const id = revisionId(run)
  return id === null ? null : views.value[id] ?? null
}

function scopeLabel(scope: PayrollJmhzOrdinaryEvidenceScope): string {
  return scope.employee_name === ''
    ? t('payroll.submissions.overview.jmhz_evidence_scope_unnamed', {
        employment: scope.employment_id,
      })
    : t('payroll.submissions.overview.jmhz_evidence_scope', {
        name: scope.employee_name,
        employment: scope.employment_id,
      })
}

function loadFailureGuidance(code: string, run: PayrollRun) {
  const guidance = jmhzEvidenceGuidance({
    employee_id: 0, employment_id: 0, employee_name: '', confirmed: false,
    resolution: 'attention_required', attention_code: code, attention_message: null,
  }, run.period_start)
  if (!guidance.path.startsWith('/payroll/runs')) {
    guidance.path = '/admin/support'
    guidance.actionKey = 'payroll.submissions.overview.jmhz_guidance.actions.support'
  }
  return guidance
}

/**
 * Výdělek OZP (10347 datum úhrady mzdy včetně pojistného, 10348 hotovost) zadává
 * účetní u vztahu s podporou zaměstnávání OZP. Srážky 10349 až 10353 odvodí
 * server ze zmrazené revize, proto se tu nevyplňují.
 */
interface OzpWageForm {
  payment_date: string
  cash_paid: '' | 'yes' | 'no'
  /** Dny odvodu pojistného 10570 a 10571 (hlášení od 01/2027); posílají se jen oba. */
  social_insurance_paid_on: string
  health_insurance_paid_on: string
  saving: boolean
  error: string
}

const ozpForms = ref<Record<string, OzpWageForm>>({})

/** Počet směn při rizikové práci (10580, hlášení od 01/2027) zadává účetní. */
interface RiskShiftForm {
  /** Vstup typu number vrací číslo, prázdný řetězec. */
  count: string | number
  saving: boolean
  error: string
}

const riskShiftForms = ref<Record<string, RiskShiftForm>>({})

function riskShiftForm(run: PayrollRun, scope: PayrollJmhzOrdinaryEvidenceScope): RiskShiftForm | null {
  const id = revisionId(run)
  return id === null ? null : riskShiftForms.value[ozpKey(id, scope.employment_id)] ?? null
}

function validShiftCount(value: string | number): boolean {
  return /^\d{1,3}$/.test(String(value).trim())
}

async function confirmRiskShifts(run: PayrollRun, scope: PayrollJmhzOrdinaryEvidenceScope) {
  const id = revisionId(run)
  const current = riskShiftForm(run, scope)
  if (id === null || current === null || !validShiftCount(current.count)) return
  current.saving = true
  current.error = ''
  try {
    await payrollApi.confirmJmhzOrdinaryEvidence(
      id,
      scope.employment_id,
      globalThis.crypto?.randomUUID?.() ?? `jmhz-shifts-${id}-${scope.employment_id}-${Date.now()}`,
      undefined,
      Number.parseInt(String(current.count).trim(), 10),
    )
    await loadRun(run)
  } catch (exception) {
    current.error = apiErrorMessage(exception) || t('payroll.submissions.overview.jmhz_risk_shifts.failed')
  } finally {
    current.saving = false
  }
}

function ozpKey(revision: number, employmentId: number): string {
  return `${revision}:${employmentId}`
}

function ozpForm(run: PayrollRun, scope: PayrollJmhzOrdinaryEvidenceScope): OzpWageForm | null {
  const id = revisionId(run)
  return id === null ? null : ozpForms.value[ozpKey(id, scope.employment_id)] ?? null
}

/** Odvozené srážky ve prospěch zaměstnavatele v celých korunách podle atributu. */
function ozpDeductions(wage: PayrollJmhzOzpWage): Array<{ attribute: string, czk: number }> {
  const sums = Array.isArray(wage.employer_deductions_minor) ? {} : wage.employer_deductions_minor
  return Object.entries(sums).map(([attribute, minor]) => ({ attribute, czk: (minor ?? 0) / 100 }))
}

async function confirmOzpWage(run: PayrollRun, scope: PayrollJmhzOrdinaryEvidenceScope) {
  const id = revisionId(run)
  const current = ozpForm(run, scope)
  if (id === null || current === null || current.cash_paid === '' || current.payment_date === '') return
  current.saving = true
  current.error = ''
  try {
    await payrollApi.confirmJmhzOrdinaryEvidence(
      id,
      scope.employment_id,
      globalThis.crypto?.randomUUID?.() ?? `jmhz-ozp-${id}-${scope.employment_id}-${Date.now()}`,
      {
        payment_date: current.payment_date,
        cash_paid: current.cash_paid === 'yes',
        ...(current.social_insurance_paid_on !== '' && current.health_insurance_paid_on !== ''
          ? {
              social_insurance_paid_on: current.social_insurance_paid_on,
              health_insurance_paid_on: current.health_insurance_paid_on,
            }
          : {}),
      },
    )
    await loadRun(run)
  } catch (exception) {
    current.error = apiErrorMessage(exception) || t('payroll.submissions.overview.jmhz_ozp_wage.failed')
  } finally {
    current.saving = false
  }
}

async function loadRun(run: PayrollRun) {
  const id = revisionId(run)
  if (id === null) return
  states.value[id] = { loading: true, error: '', scopes: [] }
  try {
    // Vztahy se jen čtou a celé se nahrazují; hluboké proxy nad stovkami
    // řádků by jen zdržovaly každé překreslení.
    const loaded = await payrollApi.jmhzOrdinaryEvidence(id)
    states.value[id].scopes = markRaw(loaded.scopes)
    states.value[id].ozpWages = markRaw((loaded.evidences ?? []).flatMap(evidence => evidence.ozp_wage
      ? [{ employment_id: evidence.employment_id, wage: evidence.ozp_wage }]
      : []))
    for (const scope of states.value[id].scopes) {
      const key = ozpKey(id, scope.employment_id)
      if (scope.attention_code === 'jmhz_ordinary_evidence_ozp_wage_required' && !ozpForms.value[key]) {
        ozpForms.value[key] = {
          payment_date: '',
          cash_paid: '',
          social_insurance_paid_on: '',
          health_insurance_paid_on: '',
          saving: false,
          error: '',
        }
      }
      if ((scope.attention_code === 'jmhz_ordinary_evidence_risk_shifts_required'
        || scope.attention_code === 'jmhz_ordinary_evidence_risk_shifts_invalid')
        && !riskShiftForms.value[key]) {
        riskShiftForms.value[key] = { count: '', saving: false, error: '' }
      }
    }
  } catch (exception) {
    const code = apiErrorCode(exception)
    states.value[id].failure = { code, technical: apiErrorMessage(exception) }
    states.value[id].error = code
      ? t(loadFailureGuidance(code, run).problemKey)
      : t('payroll.submissions.overview.jmhz_evidence_load_failed')
  } finally {
    states.value[id].loading = false
  }
}

async function load() {
  const next: Record<number, EvidenceState> = {}
  for (const run of props.runs) {
    const id = revisionId(run)
    if (id !== null) next[id] = { loading: true, error: '', scopes: [] }
  }
  states.value = next
  await Promise.all(props.runs.map(loadRun))
}

const hasRuns = computed(() => props.runs.length > 0)
// Hlídá se výčet revizí, ne celé běhy: `deep` procházel při každé změně
// i výsledek mzdy všech lidí, přitom evidence závisí jen na revizi.
watch(
  () => [props.runs, props.runs.map(run => revisionId(run) ?? `run-${run.id}`).join(',')],
  load,
  { immediate: true },
)
</script>

<template>
  <section
    id="jmhz-ordinary-evidence"
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-ordinary-evidence"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_evidence_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_evidence_description') }}
      </p>
    </div>
    <p v-if="!hasRuns" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_evidence_empty') }}
    </p>
    <div v-else class="space-y-4 p-4 sm:p-6">
      <article
        v-for="run in runs"
        :key="run.revision_id ?? run.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <h3 class="font-semibold text-neutral-900">
          {{ t('payroll.submissions.overview.jmhz_evidence_card', {
            period: formatPeriod(run.period_start.slice(0, 7)),
            revision: run.revision_no,
          }) }}
        </h3>
        <p v-if="state(run)?.loading" class="mt-3 text-sm text-neutral-500">
          {{ t('common.loading') }}
        </p>
        <template v-else-if="view(run)">
          <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <span
              v-if="view(run)!.automatic > 0"
              class="rounded-full bg-info-50 px-3 py-1 text-info-700"
            >
              {{ t('payroll.submissions.overview.jmhz_evidence_automatic_count', view(run)!.automatic) }}
            </span>
            <span
              v-if="view(run)!.confirmed > 0"
              class="rounded-full bg-success-50 px-3 py-1 text-success-700"
            >
              {{ t('payroll.submissions.overview.jmhz_evidence_confirmed_count', view(run)!.confirmed) }}
            </span>
          </div>
          <ul
            v-if="state(run)?.ozpWages?.length"
            class="mt-3 space-y-1 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
            data-test="jmhz-ozp-wage-summary"
          >
            <li v-for="item in state(run)!.ozpWages" :key="item.employment_id">
              {{ t('payroll.submissions.overview.jmhz_ozp_wage.summary', {
                employment: item.employment_id,
                date: item.wage.payment_date,
                cash: t(item.wage.cash_paid ? 'payroll.submissions.overview.jmhz_ozp_wage.cash_yes' : 'payroll.submissions.overview.jmhz_ozp_wage.cash_no'),
              }) }}
              <span v-if="!item.wage.employer_deductions_made">{{ t('payroll.submissions.overview.jmhz_ozp_wage.no_deductions') }}</span>
              <span v-for="deduction in ozpDeductions(item.wage)" :key="deduction.attribute" class="ml-2">
                {{ t('payroll.submissions.overview.jmhz_ozp_wage.deduction', { attribute: deduction.attribute, amount: deduction.czk }) }}
              </span>
            </li>
          </ul>
          <p
            v-if="view(run)!.attention > 0"
            class="mt-3 text-sm font-medium text-warning-700"
            data-test="jmhz-ordinary-evidence-pending"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_pending', view(run)!.attention) }}
          </p>
          <div
            v-for="(group, groupIndex) in view(run)!.groups"
            :key="group.key"
            class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
            data-test="jmhz-ordinary-evidence-group"
          >
            <div data-test="jmhz-evidence-guidance">
              <p class="text-sm font-medium text-neutral-700">{{ t(group.guidance.problemKey) }}</p>
              <p v-if="group.guidance.fieldKey" class="mt-1 text-sm text-neutral-700">
                {{ t(group.guidance.fieldKey) }}
              </p>
              <p v-for="version in group.guidance.versions" :key="version.key" class="mt-1 text-sm text-neutral-600">
                {{ t(version.key, { old: version.old, current: version.current }) }}
              </p>
              <p class="mt-2 text-sm text-neutral-700">{{ t(group.guidance.stepKey) }}</p>
              <p class="mt-2 text-xs font-medium text-neutral-600">
                {{ t('payroll.submissions.overview.jmhz_evidence_group_affected', group.entries.length) }}
              </p>
              <div v-if="group.sharedAction" class="mt-3 flex flex-wrap items-center gap-3">
                <RouterLink :to="group.guidance.path" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                  {{ t(group.guidance.actionKey) }}
                </RouterLink>
                <RouterLink v-if="group.guidance.agreementsPath" :to="group.guidance.agreementsPath" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                  {{ t('payroll.submissions.overview.jmhz_guidance.actions.agreements') }}
                </RouterLink>
              </div>
            </div>
            <ExpandableList
              class="mt-3"
              :items="group.entries"
              :item-key="entry => entry.scope.employment_id"
              :search-text="entry => scopeLabel(entry.scope)"
              :test-id="`jmhz-ordinary-evidence-list-${run.revision_id}-${groupIndex}`"
            >
              <template #item="{ item: entry }">
                <div
                  class="rounded-md border border-warning-500/20 bg-surface/60 p-2"
                  data-test="jmhz-ordinary-evidence-scope"
                >
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-neutral-900">{{ scopeLabel(entry.scope) }}</p>
                    <div v-if="!group.sharedAction" class="flex flex-wrap items-center gap-2">
                      <RouterLink :to="entry.guidance.path" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                        {{ t(entry.guidance.actionKey) }}
                      </RouterLink>
                      <RouterLink v-if="entry.guidance.agreementsPath" :to="entry.guidance.agreementsPath" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                        {{ t('payroll.submissions.overview.jmhz_guidance.actions.agreements') }}
                      </RouterLink>
                    </div>
                  </div>
                  <form
                    v-if="canWrite && entry.guidance.ozpWageForm && ozpForm(run, entry.scope)"
                    class="mt-2 flex flex-wrap items-end gap-3"
                    :data-test="`jmhz-ozp-wage-form-${entry.scope.employment_id}`"
                    @submit.prevent="confirmOzpWage(run, entry.scope)"
                  >
                    <p class="w-full text-xs text-neutral-600">{{ t('payroll.submissions.overview.jmhz_ozp_wage.hint') }}</p>
                    <label class="text-xs font-medium text-neutral-600">
                      {{ t('payroll.submissions.overview.jmhz_ozp_wage.payment_date') }}
                      <DateInput
                        v-model="ozpForm(run, entry.scope)!.payment_date"
                        required
                        :data-test="`jmhz-ozp-payment-date-${entry.scope.employment_id}`"
                        class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                      />
                    </label>
                    <label class="text-xs font-medium text-neutral-600">
                      {{ t('payroll.submissions.overview.jmhz_ozp_wage.cash_paid') }}
                      <select
                        v-model="ozpForm(run, entry.scope)!.cash_paid"
                        required
                        :data-test="`jmhz-ozp-cash-${entry.scope.employment_id}`"
                        class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                      >
                        <option value="" disabled>{{ t('payroll.submissions.overview.jmhz_ozp_wage.cash_select') }}</option>
                        <option value="no">{{ t('payroll.submissions.overview.jmhz_ozp_wage.cash_no') }}</option>
                        <option value="yes">{{ t('payroll.submissions.overview.jmhz_ozp_wage.cash_yes') }}</option>
                      </select>
                    </label>
                    <p class="w-full text-xs text-neutral-600">{{ t('payroll.submissions.overview.jmhz_ozp_wage.dates_hint') }}</p>
                    <label class="text-xs font-medium text-neutral-600">
                      {{ t('payroll.submissions.overview.jmhz_ozp_wage.social_paid_on') }}
                      <DateInput
                        v-model="ozpForm(run, entry.scope)!.social_insurance_paid_on"
                        :data-test="`jmhz-ozp-social-date-${entry.scope.employment_id}`"
                        class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                      />
                    </label>
                    <label class="text-xs font-medium text-neutral-600">
                      {{ t('payroll.submissions.overview.jmhz_ozp_wage.health_paid_on') }}
                      <DateInput
                        v-model="ozpForm(run, entry.scope)!.health_insurance_paid_on"
                        :data-test="`jmhz-ozp-health-date-${entry.scope.employment_id}`"
                        class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                      />
                    </label>
                    <button
                      type="submit"
                      :class="[btnFilled('success'), 'whitespace-nowrap']"
                      :disabled="ozpForm(run, entry.scope)!.saving || ozpForm(run, entry.scope)!.payment_date === '' || ozpForm(run, entry.scope)!.cash_paid === ''"
                      :data-test="`jmhz-ozp-confirm-${entry.scope.employment_id}`"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                      {{ t('payroll.submissions.overview.jmhz_ozp_wage.confirm') }}
                    </button>
                    <p v-if="ozpForm(run, entry.scope)!.error" class="w-full text-sm text-danger-700" role="alert">
                      {{ ozpForm(run, entry.scope)!.error }}
                    </p>
                  </form>
                  <form
                    v-if="canWrite && entry.guidance.riskShiftForm && riskShiftForm(run, entry.scope)"
                    class="mt-2 flex flex-wrap items-end gap-3"
                    :data-test="`jmhz-risk-shifts-form-${entry.scope.employment_id}`"
                    @submit.prevent="confirmRiskShifts(run, entry.scope)"
                  >
                    <label class="text-xs font-medium text-neutral-600">
                      {{ t('payroll.submissions.overview.jmhz_risk_shifts.label') }}
                      <input
                        v-model="riskShiftForm(run, entry.scope)!.count"
                        type="number"
                        min="0"
                        max="999"
                        step="1"
                        required
                        :data-test="`jmhz-risk-shifts-count-${entry.scope.employment_id}`"
                        class="mt-1 block w-28 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                      >
                    </label>
                    <button
                      type="submit"
                      :class="[btnFilled('success'), 'whitespace-nowrap']"
                      :disabled="riskShiftForm(run, entry.scope)!.saving || !validShiftCount(String(riskShiftForm(run, entry.scope)!.count))"
                      :data-test="`jmhz-risk-shifts-confirm-${entry.scope.employment_id}`"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                      {{ t('payroll.submissions.overview.jmhz_risk_shifts.confirm') }}
                    </button>
                    <p v-if="riskShiftForm(run, entry.scope)!.error" class="w-full text-sm text-danger-700" role="alert">
                      {{ riskShiftForm(run, entry.scope)!.error }}
                    </p>
                  </form>
                  <details class="mt-2 text-xs text-neutral-500">
                    <summary class="cursor-pointer">{{ t('payroll.submissions.overview.jmhz_guidance.technical_details') }}</summary>
                    <p class="mt-2 break-words">{{ entry.scope.attention_code }}</p>
                    <p class="mt-1 break-words">{{ entry.scope.attention_message }}</p>
                  </details>
                </div>
              </template>
            </ExpandableList>
          </div>
          <p
            v-if="state(run)?.scopes.length === 0"
            class="mt-3 text-sm text-neutral-500"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_no_scopes') }}
          </p>
          <p
            v-else-if="view(run)!.attention === 0"
            class="mt-3 text-sm font-medium text-success-700"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_all_resolved') }}
          </p>
        </template>
        <!--
          Chyba načtení bez tlačítka byla slepá ulička: karta se načítá sama
          při otevření záložky, takže po výpadku neexistoval žádný způsob, jak
          si ji vyžádat znovu — kromě reloadu celé stránky.
        -->
        <div
          v-if="state(run)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          <p>{{ state(run)?.error }}</p>
          <template v-if="state(run)?.failure?.code">
            <p class="mt-2">{{ t(loadFailureGuidance(state(run)!.failure!.code, run).stepKey) }}</p>
            <RouterLink :to="loadFailureGuidance(state(run)!.failure!.code, run).path" :class="[btnOutlineSm('danger'), 'mt-3 whitespace-nowrap']">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t(loadFailureGuidance(state(run)!.failure!.code, run).actionKey) }}
            </RouterLink>
          </template>
          <details v-if="state(run)?.failure" class="mt-3 text-xs">
            <summary class="cursor-pointer">{{ t('payroll.submissions.overview.jmhz_guidance.technical_details') }}</summary>
            <p class="mt-2 break-words">{{ state(run)?.failure?.code }}</p>
            <p class="mt-1 break-words">{{ state(run)?.failure?.technical }}</p>
          </details>
          <button
            type="button"
            :class="[btnOutline('danger'), 'mt-3']"
            :disabled="state(run)?.loading"
            :data-test="`jmhz-ordinary-evidence-retry-${run.revision_id}`"
            @click="loadRun(run)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ t('common.retry') }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzOrdinaryEvidenceScope,
  type PayrollRun,
} from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ runs: PayrollRun[] }>()
const { t } = useI18n()

interface EvidenceState {
  loading: boolean
  error: string
  scopes: PayrollJmhzOrdinaryEvidenceScope[]
}

const states = ref<Record<number, EvidenceState>>({})

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function state(run: PayrollRun): EvidenceState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

function attentionScopes(run: PayrollRun): PayrollJmhzOrdinaryEvidenceScope[] {
  return state(run)?.scopes.filter(scope => scope.resolution === 'attention_required') ?? []
}

function automaticCount(run: PayrollRun): number {
  return state(run)?.scopes.filter(
    scope => scope.resolution === 'automatic_on_preparation',
  ).length ?? 0
}

function confirmedCount(run: PayrollRun): number {
  return state(run)?.scopes.filter(scope => scope.resolution === 'confirmed').length ?? 0
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

function attentionPath(scope: PayrollJmhzOrdinaryEvidenceScope): string {
  if (scope.attention_code === 'jmhz_ordinary_evidence_profile_missing') {
    return '/payroll/runs'
  }
  if (scope.attention_code === 'jmhz_ordinary_evidence_deduction_conflict') {
    return `/payroll/enforcement?person=${scope.employee_id}`
  }
  return `/payroll/people?employment=${scope.employment_id}`
}

function attentionActionKey(scope: PayrollJmhzOrdinaryEvidenceScope): string {
  if (scope.attention_code === 'jmhz_ordinary_evidence_profile_missing') {
    return 'payroll.submissions.overview.jmhz_evidence_attention_run_action'
  }
  return scope.attention_code === 'jmhz_ordinary_evidence_deduction_conflict'
    ? 'payroll.submissions.overview.jmhz_evidence_attention_deductions_action'
    : 'payroll.submissions.overview.jmhz_evidence_attention_employment_action'
}

async function load() {
  const next: Record<number, EvidenceState> = {}
  for (const run of props.runs) {
    const id = revisionId(run)
    if (id !== null) next[id] = { loading: true, error: '', scopes: [] }
  }
  states.value = next
  await Promise.all(props.runs.map(async run => {
    const id = revisionId(run)
    if (id === null) return
    try {
      states.value[id].scopes = (await payrollApi.jmhzOrdinaryEvidence(id)).scopes
    } catch (exception) {
      states.value[id].error = apiErrorMessage(
        exception,
        t('payroll.submissions.overview.jmhz_evidence_load_failed'),
      )
    } finally {
      states.value[id].loading = false
    }
  }))
}

const hasRuns = computed(() => props.runs.length > 0)
watch(() => props.runs, load, { immediate: true, deep: true })
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
            period: run.period_start.slice(0, 7),
            revision: run.revision_no,
          }) }}
        </h3>
        <p v-if="state(run)?.loading" class="mt-3 text-sm text-neutral-500">
          {{ t('common.loading') }}
        </p>
        <template v-else>
          <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <span
              v-if="automaticCount(run) > 0"
              class="rounded-full bg-info-50 px-3 py-1 text-info-700"
            >
              {{ t('payroll.submissions.overview.jmhz_evidence_automatic_count', automaticCount(run)) }}
            </span>
            <span
              v-if="confirmedCount(run) > 0"
              class="rounded-full bg-success-50 px-3 py-1 text-success-700"
            >
              {{ t('payroll.submissions.overview.jmhz_evidence_confirmed_count', confirmedCount(run)) }}
            </span>
          </div>
          <p
            v-if="attentionScopes(run).length > 0"
            class="mt-3 text-sm font-medium text-warning-700"
            data-test="jmhz-ordinary-evidence-pending"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_pending', attentionScopes(run).length) }}
          </p>
          <div
            v-for="scope in attentionScopes(run)"
            :key="scope.employment_id"
            class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
            data-test="jmhz-ordinary-evidence-scope"
          >
            <p class="text-sm font-semibold text-neutral-900">{{ scopeLabel(scope) }}</p>
            <p class="mt-1 text-sm text-neutral-700">
              {{ scope.attention_message ?? t('payroll.submissions.overview.jmhz_evidence_attention_default') }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
              <RouterLink :to="attentionPath(scope)" :class="btnOutlineSm('warning')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t(attentionActionKey(scope)) }}
              </RouterLink>
            </div>
          </div>
          <p
            v-if="state(run)?.scopes.length === 0"
            class="mt-3 text-sm text-neutral-500"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_no_scopes') }}
          </p>
          <p
            v-else-if="attentionScopes(run).length === 0"
            class="mt-3 text-sm font-medium text-success-700"
          >
            {{ t('payroll.submissions.overview.jmhz_evidence_all_resolved') }}
          </p>
        </template>
        <p
          v-if="state(run)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(run)?.error }}
        </p>
      </article>
    </div>
  </section>
</template>

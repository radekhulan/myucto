<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzControlFinding,
  type PayrollJmhzControlReport,
  type PayrollJmhzPvpojOffice,
  type PayrollJmhzXmlDryRun,
  type PayrollJmhzXmlDryRunBlocker,
  type PayrollRun,
} from '@/api/payroll'
import {
  payrollAbsenceApi,
  type PayrollAbsenceEmployment,
} from '@/api/payrollAbsences'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ runs: PayrollRun[] }>()

interface DryRunState {
  running: boolean
  error: string
  result: PayrollJmhzXmlDryRun | null
  showXml: boolean
}

const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const states = ref<Record<number, DryRunState>>({})
/**
 * Registrace u OSSZ, za které se z revize podává. Měsíční hlášení je podání
 * ZA REGISTRACI, takže test nad během přes víc mzdových účtáren musí vědět,
 * kterou z nich zkouší — jinak by se lidé jedné účtárny vykázali pod cizím
 * variabilním symbolem.
 */
const offices = ref<Record<number, PayrollJmhzPvpojOffice[]>>({})
const selectedOffice = ref<Record<number, number | null>>({})
const remediationEmployments = ref<PayrollAbsenceEmployment[]>([])
const remediationContextRequested = ref(false)

watch(() => props.runs, async runs => {
  const loaded: Record<number, PayrollJmhzPvpojOffice[]> = {}
  const selected: Record<number, number | null> = {}
  const ids = runs
    .map(revisionId)
    .filter((id): id is number => id !== null)
  const responses = await Promise.allSettled(
    ids.map(id => payrollApi.jmhzPvpojOffices(id)),
  )
  responses.forEach((response, index) => {
    const id = ids[index]!
    const rows = response.status === 'fulfilled' ? response.value : []
    loaded[id] = rows
    const submittable = rows.filter(office => office.submittable)
    selected[id] = submittable.length === 1
      ? submittable[0]!.office_id
      : selectedOffice.value[id] ?? null
  })
  offices.value = loaded
  selectedOffice.value = selected
}, { immediate: true, deep: true })

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function officeOptions(run: PayrollRun): PayrollJmhzPvpojOffice[] {
  const id = revisionId(run)
  return id === null ? [] : offices.value[id] ?? []
}

function submittableOffices(revision: number): PayrollJmhzPvpojOffice[] {
  return (offices.value[revision] ?? []).filter(office => office.submittable)
}

async function ensureOffices(revision: number): Promise<void> {
  if (offices.value[revision] !== undefined) return
  const rows = await payrollApi.jmhzPvpojOffices(revision).catch(() => [])
  offices.value = { ...offices.value, [revision]: rows }
  const submittable = rows.filter(office => office.submittable)
  if (selectedOffice.value[revision] == null && submittable.length === 1) {
    selectedOffice.value = {
      ...selectedOffice.value,
      [revision]: submittable[0]!.office_id,
    }
  }
}

async function ensureRemediationContext(): Promise<void> {
  if (remediationContextRequested.value) return
  remediationContextRequested.value = true
  remediationEmployments.value = await payrollAbsenceApi.context().catch(() => [])
}

function state(run: PayrollRun): DryRunState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

async function run(payrollRun: PayrollRun) {
  const id = revisionId(payrollRun)
  if (id === null || !canWrite.value) return
  const current: DryRunState = {
    running: true,
    error: '',
    result: null,
    showXml: false,
  }
  states.value = { ...states.value, [id]: current }
  try {
    // Registrace se mohou ještě načítat — spustit test dřív, než je známý
    // seznam účtáren, by u víceúčtárenské revize znamenalo nacvičit naslepo.
    await ensureOffices(id)
    if (submittableOffices(id).length > 1 && selectedOffice.value[id] == null) {
      states.value[id].error = t(
        'payroll.submissions.overview.jmhz_social_multiple_offices',
      )
      return
    }
    const preparation = await payrollApi.freezeJmhzPreparation(
      id,
      crypto.randomUUID(),
    )
    const result = await payrollApi.jmhzXmlDryRun(
      preparation.id,
      'test',
      selectedOffice.value[id] ?? null,
    )
    states.value[id].result = result
    if (result.status === 'blocked'
      && result.blockers.some(blocker => blocker.entity_id !== null)
    ) {
      await ensureRemediationContext()
    }
  } catch (exception) {
    states.value[id].error = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_dry_run_failed'),
    )
  } finally {
    states.value[id].running = false
  }
}

function blockerLabel(code: string): string {
  const key = `payroll.submissions.overview.jmhz_dry_run_blockers.${code}`
  const translated = t(key)
  return translated === key
    ? t('payroll.submissions.overview.jmhz_dry_run_blockers.unknown')
    : translated
}

interface BlockerGroup {
  key: string
  blocker: PayrollJmhzXmlDryRunBlocker
  count: number
  entityIds: number[]
}

function groupedBlockers(blockers: PayrollJmhzXmlDryRunBlocker[]): BlockerGroup[] {
  const groups = new Map<string, BlockerGroup>()
  for (const blocker of blockers) {
    const key = JSON.stringify([
      blocker.code,
      blocker.entity_type,
      [...blocker.attribute_ids].sort(),
    ])
    const current = groups.get(key)
    if (current) {
      current.count++
      if (blocker.entity_id !== null
        && !current.entityIds.includes(blocker.entity_id)
      ) {
        current.entityIds.push(blocker.entity_id)
      }
    } else {
      groups.set(key, {
        key,
        blocker,
        count: 1,
        entityIds: blocker.entity_id === null ? [] : [blocker.entity_id],
      })
    }
  }
  return [...groups.values()]
}

function blockerTarget(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number | null = blocker.entity_id,
): RouteLocationRaw | null {
  if ([
    'jmhz_attribute_10116_unresolved',
    'jmhz_attribute_10546_unresolved',
    'jmhz_interaction_in13_unresolved',
    'jmhz_interaction_in28_unresolved',
    'jmhz_interaction_in30_unresolved',
    'jmhz_ordinary_evidence_missing',
  ].includes(blocker.code)) {
    return { name: 'payroll-submissions', hash: '#jmhz-ordinary-evidence' }
  }
  if (blocker.code === 'jmhz_average_hourly_earning_missing') {
    return entityId === null
      ? { name: 'payroll-absences', query: { tab: 'averages' } }
      : {
          name: 'payroll-absences',
          query: { employment: String(entityId), tab: 'averages' },
        }
  }
  if (blocker.code === 'jmhz_work_month_not_approved') {
    return entityId === null
      ? { name: 'payroll-time' }
      : { name: 'payroll-time', query: { employment: String(entityId) } }
  }
  if (blocker.code === 'jmhz_scenario1_earnings_vector_incomplete') {
    return { name: 'payroll-components' }
  }
  switch (blocker.entity_type) {
    case 'employment':
      return entityId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { employment: String(entityId) } }
    case 'person':
    case 'employee':
      return entityId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { person: String(entityId) } }
    case 'component':
      return { name: 'payroll-components' }
    case 'office':
      return { name: 'payroll-settings', query: { tab: 'offices' } }
    case 'run':
    case 'revision':
    case 'preparation':
      return { name: 'payroll-runs' }
    default:
      return null
  }
}

function blockerActionLabel(blocker: PayrollJmhzXmlDryRunBlocker): string {
  const codeKey = `payroll.submissions.overview.jmhz_dry_run_action_codes.${blocker.code}`
  const codeTranslated = t(codeKey)
  if (codeTranslated !== codeKey) return codeTranslated
  const key = `payroll.submissions.overview.jmhz_dry_run_actions.${blocker.entity_type}`
  const translated = t(key)
  return translated === key
    ? t('payroll.submissions.overview.jmhz_dry_run_actions.default')
    : translated
}

function blockerUsesSharedTarget(code: string): boolean {
  return [
    'jmhz_attribute_10116_unresolved',
    'jmhz_attribute_10546_unresolved',
    'jmhz_interaction_in13_unresolved',
    'jmhz_interaction_in28_unresolved',
    'jmhz_interaction_in30_unresolved',
    'jmhz_ordinary_evidence_missing',
    'jmhz_scenario1_earnings_vector_incomplete',
  ].includes(code)
}

function blockerUsesAgendaTarget(group: BlockerGroup): boolean {
  return blockerUsesSharedTarget(group.blocker.code) || group.entityIds.length > 10
}

function blockerGroupTarget(group: BlockerGroup): RouteLocationRaw | null {
  return blockerUsesAgendaTarget(group)
    ? blockerTarget(group.blocker, null)
    : blockerTarget(group.blocker)
}

function entityIdPreview(entityIds: number[]): string {
  const visible = entityIds.slice(0, 10).join(', ')
  return entityIds.length > 10 ? `${visible}, …` : visible
}

function remediationLabel(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number,
  index: number,
): string {
  const employment = blocker.entity_type === 'employment'
    ? remediationEmployments.value.find(item => item.id === entityId)
    : ['person', 'employee'].includes(blocker.entity_type)
      ? remediationEmployments.value.find(item => item.employee_id === entityId)
      : undefined
  if (employment !== undefined) {
    return blocker.entity_type === 'employment'
      ? `${employment.full_name} · ${employment.code}`
      : employment.full_name
  }
  return t('payroll.submissions.overview.jmhz_dry_run_actions.record', {
    number: index + 1,
  })
}

interface ControlGroup {
  key: string
  title: string
  tone: string
  findings: PayrollJmhzControlFinding[]
}

/**
 * Tři skupiny nálezů se liší dopadem, ne závažností textu: nepropustná vada
 * podání zneplatní, propustná ho nechá projít s chybnými daty a mezera
 * v pokrytí znamená, že jsme kontrolu vůbec nevykonali.
 */
function controlGroups(report: PayrollJmhzControlReport): ControlGroup[] {
  return ([
    {
      key: 'blocking',
      title: 'payroll.submissions.overview.jmhz_controls_blocking',
      tone: 'border-danger-500/30 bg-danger-50 text-danger-700',
      findings: report.blocking,
    },
    {
      key: 'gaps',
      title: 'payroll.submissions.overview.jmhz_controls_gaps',
      tone: 'border-warning-500/30 bg-warning-50 text-warning-700',
      findings: report.coverage_gaps,
    },
    {
      key: 'warnings',
      title: 'payroll.submissions.overview.jmhz_controls_warnings',
      tone: 'border-neutral-300 bg-neutral-50 text-neutral-700',
      findings: report.warnings,
    },
  ] satisfies ControlGroup[]).filter(group => group.findings.length > 0)
}

async function copyXml(payrollRun: PayrollRun) {
  const xml = state(payrollRun)?.result?.xml
  if (xml) await navigator.clipboard.writeText(xml)
}
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-xml-dry-run"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_dry_run_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_dry_run_description') }}
      </p>
    </div>
    <p v-if="runs.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_dry_run_empty') }}
    </p>
    <div v-else class="space-y-4 p-4">
      <article
        v-for="payrollRun in runs"
        :key="payrollRun.revision_id ?? payrollRun.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.jmhz_dry_run_card', {
              period: payrollRun.period_start.slice(0, 7),
              revision: payrollRun.revision_no,
            }) }}
          </h3>
          <label
            v-if="officeOptions(payrollRun).length > 1"
            class="flex flex-wrap items-center gap-2 text-sm text-neutral-600"
            :data-test="`jmhz-dry-run-office-${payrollRun.revision_id}`"
          >
            <span class="whitespace-nowrap">
              {{ t('payroll.submissions.overview.jmhz_dry_run_office') }}
            </span>
            <select
              v-model="selectedOffice[payrollRun.revision_id!]"
              class="rounded-lg border border-neutral-300 bg-surface px-2 py-1 text-sm"
            >
              <option :value="null">
                {{ t('payroll.submissions.overview.jmhz_dry_run_office_choose') }}
              </option>
              <option
                v-for="office in officeOptions(payrollRun)"
                :key="office.office_id"
                :value="office.office_id"
                :disabled="!office.submittable"
              >
                {{ office.code }} · {{ office.name }}
                {{ office.submittable
                  ? ''
                  : t('payroll.submissions.overview.jmhz_office_variable_symbol_missing') }}
              </option>
            </select>
          </label>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canWrite || state(payrollRun)?.running
              || (officeOptions(payrollRun).length > 1
                && selectedOffice[payrollRun.revision_id!] == null)"
            :data-test="`jmhz-dry-run-start-${payrollRun.revision_id}`"
            @click="run(payrollRun)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ state(payrollRun)?.running
              ? t('common.loading')
              : t('payroll.submissions.overview.jmhz_dry_run_start') }}
          </button>
        </div>

        <template v-if="state(payrollRun)?.result">
          <div
            v-if="state(payrollRun)!.result!.status !== 'blocked'"
            class="mt-3 rounded-lg p-3"
            :class="state(payrollRun)!.result!.status === 'dry_run_valid'
              ? 'border border-success-500/30 bg-success-50'
              : 'border border-warning-500/30 bg-warning-50'"
          >
            <p
              class="text-sm font-medium"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid'
                ? 'text-success-700'
                : 'text-warning-700'"
            >
              {{ state(payrollRun)!.result!.status === 'dry_run_valid'
                ? t('payroll.submissions.overview.jmhz_dry_run_valid', {
                  version: state(payrollRun)!.result!.schema?.data_version ?? '',
                })
                : t('payroll.submissions.overview.jmhz_dry_run_incomplete') }}
            </p>
            <p
              class="mt-1 break-all text-xs"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid'
                ? 'text-success-700'
                : 'text-warning-700'"
            >
              {{ state(payrollRun)!.result!.xml_sha256?.slice(0, 16) }}…
            </p>

            <p
              v-if="state(payrollRun)!.result!.deadline"
              class="mt-2 text-xs text-neutral-600"
              data-test="jmhz-dry-run-deadline"
            >
              {{ t('payroll.submissions.overview.jmhz_deadline', {
                from: state(payrollRun)!.result!.deadline!.earliest_submission_on,
                to: state(payrollRun)!.result!.deadline!.due_on,
              }) }}
            </p>

            <div
              v-if="state(payrollRun)!.result!.controls"
              class="mt-3 space-y-3"
              data-test="jmhz-dry-run-controls"
            >
              <p class="text-xs text-neutral-600">
                {{ t('payroll.submissions.overview.jmhz_controls_summary', {
                  passed: state(payrollRun)!.result!.controls!.counts.passed,
                  failed: state(payrollRun)!.result!.controls!.counts.failed,
                  remote: state(payrollRun)!.result!.controls!.counts.not_evaluable,
                  gaps: state(payrollRun)!.result!.controls!.coverage_gaps.length,
                }) }}
              </p>
              <details
                v-if="state(payrollRun)!.result!.controls!.deviations?.length"
                class="rounded-lg border border-neutral-300 bg-neutral-50 p-3 text-neutral-700"
                data-test="jmhz-controls-deviations"
              >
                <summary class="cursor-pointer text-sm font-medium">
                  {{ t('payroll.submissions.overview.jmhz_controls_deviations', {
                    count: state(payrollRun)!.result!.controls!.deviations.length,
                  }) }}
                </summary>
                <ul class="mt-2 space-y-1 text-sm">
                  <li
                    v-for="deviation in state(payrollRun)!.result!.controls!.deviations"
                    :key="deviation.control_id"
                  >
                    <span class="font-mono text-xs opacity-75">{{ deviation.control_id }}</span>
                    {{ deviation.reason }}
                  </li>
                </ul>
              </details>
              <div
                v-for="group in controlGroups(state(payrollRun)!.result!.controls!)"
                :key="group.key"
                class="rounded-lg border p-3"
                :class="group.tone"
                :data-test="`jmhz-controls-${group.key}`"
              >
                <p class="text-sm font-medium">
                  {{ t(group.title, { count: group.findings.length }) }}
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                  <li v-for="finding in group.findings" :key="`${group.key}-${finding.control_id}-${finding.form_ordinal ?? ''}`">
                    <span class="font-mono text-xs opacity-75">
                      {{ finding.error_code ?? finding.control_id }}
                    </span>
                    {{ finding.message }}
                    <span
                      v-if="finding.form_ordinal !== null"
                      class="text-xs opacity-75"
                    >
                      ({{ t('payroll.submissions.overview.jmhz_controls_form', {
                        ordinal: finding.form_ordinal + 1,
                      }) }})
                    </span>
                  </li>
                </ul>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="state(payrollRun)!.showXml = !state(payrollRun)!.showXml"
              >
                {{ state(payrollRun)!.showXml
                  ? t('payroll.submissions.overview.jmhz_dry_run_hide_xml')
                  : t('payroll.submissions.overview.jmhz_dry_run_show_xml') }}
              </button>
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="copyXml(payrollRun)"
              >
                {{ t('payroll.submissions.overview.jmhz_dry_run_copy_xml') }}
              </button>
            </div>
            <pre
              v-if="state(payrollRun)!.showXml"
              class="mt-3 max-h-96 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
            >{{ state(payrollRun)!.result!.xml }}</pre>
          </div>
          <div
            v-else
            class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
          >
            <p class="text-sm font-medium text-warning-700">
              {{ t('payroll.submissions.overview.jmhz_dry_run_blocked', {
                count: groupedBlockers(state(payrollRun)!.result!.blockers).length,
                records: state(payrollRun)!.result!.blockers.length,
              }) }}
            </p>
            <ul class="mt-3 space-y-3 text-sm text-warning-700">
              <li
                v-for="group in groupedBlockers(state(payrollRun)!.result!.blockers)"
                :key="group.key"
                class="rounded-lg border border-warning-500/20 bg-surface/60 p-3"
                data-test="jmhz-dry-run-blocker"
              >
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ blockerLabel(group.blocker.code) }}</p>
                    <p v-if="group.count > 1" class="mt-1 text-xs opacity-80">
                      {{ t('payroll.submissions.overview.jmhz_dry_run_blocker_occurrences', {
                        count: group.count,
                      }) }}
                    </p>
                  </div>
                  <RouterLink
                    v-if="blockerGroupTarget(group)
                      && (group.entityIds.length <= 1
                        || blockerUsesAgendaTarget(group))"
                    :to="blockerGroupTarget(group)!"
                    :class="btnOutline('warning')"
                    data-test="jmhz-dry-run-remediation"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.edit" />
                    </svg>
                    {{ blockerActionLabel(group.blocker) }}
                  </RouterLink>
                </div>
                <details
                  v-if="blockerTarget(group.blocker) && group.entityIds.length > 1
                    && group.entityIds.length <= 10
                    && !blockerUsesAgendaTarget(group)"
                  class="mt-2"
                  data-test="jmhz-dry-run-remediation-list"
                >
                  <summary class="cursor-pointer font-medium underline decoration-dotted underline-offset-4">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_actions.multiple', {
                      count: group.entityIds.length,
                    }) }}
                  </summary>
                  <div class="mt-2 flex flex-wrap gap-2">
                    <RouterLink
                      v-for="(entityId, index) in group.entityIds"
                      :key="entityId"
                      :to="blockerTarget(group.blocker, entityId)!"
                      :class="btnOutline('warning')"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.edit" />
                      </svg>
                      {{ remediationLabel(group.blocker, entityId, index) }}
                    </RouterLink>
                  </div>
                </details>
                <details
                  v-if="group.blocker.attribute_ids.length || group.entityIds.length"
                  class="mt-2 text-xs opacity-80"
                  data-test="jmhz-dry-run-technical-detail"
                >
                  <summary class="cursor-pointer">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_technical_detail') }}
                  </summary>
                  <p v-if="group.blocker.attribute_ids.length" class="mt-1 font-mono">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_attribute_ids', {
                      ids: group.blocker.attribute_ids.join(', '),
                    }) }}
                  </p>
                  <p v-if="group.entityIds.length" class="mt-1 font-mono">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_entity_ids', {
                      ids: entityIdPreview(group.entityIds),
                    }) }}
                  </p>
                </details>
              </li>
            </ul>
          </div>
          <p class="mt-3 text-xs text-neutral-500">
            {{ state(payrollRun)!.result!.official_submission.reason }}
          </p>
        </template>

        <p
          v-if="state(payrollRun)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(payrollRun)?.error }}
        </p>
      </article>
    </div>
  </section>
</template>

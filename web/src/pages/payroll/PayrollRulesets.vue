<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  crownsToMinor,
  minorToCrowns,
  payrollRulesetsApi,
  percentToRate,
  rateToPercent,
  type PayrollRuleParameter,
  type PayrollRulesetCommand,
  type PayrollRulesetDetail,
  type PayrollRulesetDiff,
  type PayrollRulesetDomainGroup,
  type PayrollRulesetDomainStatus,
  type PayrollRulesetImpactPreview,
  type PayrollRulesetOverview,
  type PayrollRulesetSource,
  type PayrollRulesetSummary,
} from '@/api/payrollRulesets'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const overview = ref<PayrollRulesetOverview | null>(null)
const detail = ref<PayrollRulesetDetail | null>(null)
const diff = ref<PayrollRulesetDiff | null>(null)
const impactPreview = ref<PayrollRulesetImpactPreview | null>(null)
const impactPreviewLoading = ref(false)
const activationPreviewConfirmed = ref(false)
const TABS = ['parameters', 'sources', 'diff', 'audit'] as const
type RulesetTab = (typeof TABS)[number]
const tab = ref<RulesetTab>('parameters')
const reason = ref('')
const drafts = reactive<Record<string, string>>({})

const COLUMNS: ColumnDef[] = [
  { key: 'version', labelKey: 'payroll.rulesets.column.version', required: true },
  { key: 'effective', labelKey: 'payroll.rulesets.column.effective' },
  { key: 'lifecycle', labelKey: 'payroll.rulesets.column.lifecycle' },
  { key: 'source', labelKey: 'payroll.rulesets.column.source' },
  { key: 'actions', labelKey: 'payroll.rulesets.open', required: true },
]
const tbl = useTablePrefs('payroll-rulesets', COLUMNS)

const canEdit = computed(() => auth.isSuperadmin)
const impactPreviewMatchesCandidate = computed(() =>
  impactPreview.value !== null
  && detail.value !== null
  && impactPreview.value.ruleset.ruleset_id === detail.value.ruleset_id
  && impactPreview.value.ruleset.row_version === detail.value.row_version
  && impactPreview.value.ruleset.canonical_hash === detail.value.canonical_hash,
)
const canActivate = computed(() =>
  detail.value?.next_command === 'activate'
  && impactPreviewMatchesCandidate.value
  && activationPreviewConfirmed.value,
)

const lifecycleClass: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  reviewed: 'bg-warning-50 text-warning-600',
  approved: 'bg-primary-50 text-primary-700',
  active: 'bg-success-50 text-success-600',
  superseded: 'bg-neutral-100 text-neutral-500',
}

/**
 * Neutrální barva = „tady po vás nikdo nic nechce" (ruční posouzení),
 * jantarová = „čeká to na vás" (neúčinná verze), červená = rozbitá sada.
 * Dřív měly všechny tři stejný jantarový štítek „Výpočet blokován", takže
 * vědomé rozhodnutí aplikace vypadalo jako neodbavená fronta.
 */
const domainStatusClass: Record<string, string> = {
  ready: 'bg-success-50 text-success-600',
  manual_review: 'bg-neutral-100 text-neutral-600',
  awaiting_activation: 'bg-warning-50 text-warning-600',
  coverage_issue: 'bg-danger-50 text-danger-500',
  missing: 'bg-danger-50 text-danger-500',
}

function domainStatus(group: PayrollRulesetDomainGroup): PayrollRulesetDomainStatus {
  return group.status ?? (group.calculation_ready ? 'ready' : 'awaiting_activation')
}

/** Kolika parametrů se ruční posouzení v doméně týká — a kolika ne. */
function manualReviewShare(group: PayrollRulesetDomainGroup): string | null {
  const manual = group.manual_review_parameter_count ?? 0
  const total = group.parameter_count ?? 0
  if (manual === 0 || total === 0) return null
  return manual >= total
    ? t('payroll.rulesets.manual_review_all', { total })
    : t('payroll.rulesets.manual_review_share', { manual, total })
}

function isManualReview(parameter: PayrollRuleParameter): boolean {
  return parameter.capability === 'manual_review' || parameter.type === 'manual_review'
}

/**
 * Doložení místo odklikávání.
 *
 * Zákazník už legislativní sazby neschvaluje — dodáváme je jako účinné a ručíme
 * za ně my. Aby to nebylo tvrzení bez opory, musí být u každé domény vidět,
 * ODKUD hodnota je: název předpisu nebo úřadu, odkaz a datum stažení. To je
 * věcně silnější než potvrzení, které stejně nikdo nečte.
 */
function domainSources(group: PayrollRulesetDomainGroup): PayrollRulesetSource[] {
  const byId = new Map<string, PayrollRulesetSource>()
  for (const version of group.versions) {
    for (const source of version.sources ?? []) {
      if (source.id && !byId.has(source.id)) byId.set(source.id, source)
    }
  }
  return [...byId.values()]
}

/**
 * Přiřazení zdroje k JEDNOTLIVÉMU parametru je poctivé jen tam, kde má verze
 * právě jeden zdroj — pak není co domýšlet. U vícezdrojových domén se zdroje
 * ukazují za celou verzi; vymýšlet, který paragraf stojí za kterým číslem, by
 * vyrobilo doložení, které vypadá přesně a přesné není.
 */
const singleParameterSource = computed<PayrollRulesetSource | null>(() => {
  const sources = detail.value?.sources ?? []
  return sources.length === 1 ? sources[0] : null
})

function sourceLabel(source: PayrollRulesetSource): string {
  return source.title ?? source.id ?? ''
}

/** Český název parametru; klíč zůstává vidět jako drobný doplněk pod ním. */
function parameterName(parameter: PayrollRuleParameter): string {
  return parameter.label ?? parameter.key
}

/** Název pro řádek diffu, kde chodí jen klíč. */
function nameForKey(key: string): string {
  return detail.value?.parameters.find(parameter => parameter.key === key)?.label ?? key
}

async function load() {
  loading.value = true
  try {
    overview.value = await payrollRulesetsApi.overview()
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.load_failed')))
  } finally {
    loading.value = false
  }
}

async function open(summary: PayrollRulesetSummary) {
  tab.value = 'parameters'
  reason.value = ''
  impactPreview.value = null
  activationPreviewConfirmed.value = false
  Object.keys(drafts).forEach(key => delete drafts[key])
  try {
    detail.value = await payrollRulesetsApi.detail(summary.ruleset_id)
    diff.value = summary.has_default
      ? await payrollRulesetsApi.diff(summary.ruleset_id, 'default')
      : null
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.load_failed')))
  }
}

function close() {
  detail.value = null
  diff.value = null
  impactPreview.value = null
  activationPreviewConfirmed.value = false
}

/**
 * Popisy výčtových hodnot z aktuální verze — aby i řádek diffu ukázal
 * „zaokrouhlit nahoru na celé koruny" místo `ceil-to-1-czk`.
 */
const valueLabels = computed<Record<string, string>>(() => {
  const map: Record<string, string> = {}
  for (const parameter of detail.value?.parameters ?? []) {
    if (typeof parameter.value === 'string' && parameter.value_label) {
      map[parameter.value] = parameter.value_label
    }
  }
  return map
})

/** Interní jednotky se v UI neukazují — haléře jako Kč, sazby jako procenta. */
function displayValue(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'text' && typeof parameter.value === 'string') {
    return parameter.value_label ?? valueLabels.value[parameter.value] ?? parameter.value
  }
  if (parameter.type === 'money_minor' && typeof parameter.value === 'number') {
    return `${minorToCrowns(parameter.value).toLocaleString('cs-CZ', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })} ${t('payroll.rulesets.unit.czk')}`
  }
  if (parameter.type === 'decimal_rate' && typeof parameter.value === 'string') {
    return `${rateToPercent(parameter.value).toLocaleString('cs-CZ', {
      maximumFractionDigits: 4,
    })} %`
  }
  if (parameter.type === 'boolean') {
    return parameter.value ? t('common.yes') : t('common.no')
  }
  return String(parameter.value ?? '')
}

function editableValue(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'money_minor' && typeof parameter.value === 'number') {
    return String(minorToCrowns(parameter.value))
  }
  if (parameter.type === 'decimal_rate' && typeof parameter.value === 'string') {
    return String(rateToPercent(parameter.value))
  }
  return String(parameter.value ?? '')
}

function draftFor(parameter: PayrollRuleParameter): string {
  return drafts[parameter.key] ?? editableValue(parameter)
}

function setDraft(parameter: PayrollRuleParameter, value: string) {
  drafts[parameter.key] = value
}

function isEditable(parameter: PayrollRuleParameter): boolean {
  return ['money_minor', 'decimal_rate', 'integer', 'text'].includes(parameter.type)
}

function unitLabel(parameter: PayrollRuleParameter): string {
  if (parameter.type === 'money_minor') return t('payroll.rulesets.unit.czk')
  if (parameter.type === 'decimal_rate') return '%'
  return ''
}

/** @returns patch pouze se skutečně změněnými parametry (merge per klíč na BE). */
function changedParameters(): Record<string, Record<string, unknown>> {
  const patch: Record<string, Record<string, unknown>> = {}
  for (const parameter of detail.value?.parameters ?? []) {
    const draft = drafts[parameter.key]
    if (draft === undefined || draft === editableValue(parameter)) continue
    if (parameter.type === 'money_minor') {
      patch[parameter.key] = { type: parameter.type, value: crownsToMinor(Number(draft)) }
    } else if (parameter.type === 'decimal_rate') {
      patch[parameter.key] = { type: parameter.type, value: percentToRate(Number(draft)) }
    } else if (parameter.type === 'integer') {
      patch[parameter.key] = { type: parameter.type, value: Math.round(Number(draft)) }
    } else {
      patch[parameter.key] = { type: parameter.type, value: draft }
    }
  }
  return patch
}

const hasChanges = computed(() => Object.keys(changedParameters()).length > 0)

async function save() {
  if (!detail.value) return
  const parameters = changedParameters()
  if (Object.keys(parameters).length === 0) {
    toast.warning(t('payroll.rulesets.nothing_changed'))
    return
  }
  if (reason.value.trim() === '') {
    toast.warning(t('payroll.rulesets.reason_required'))
    return
  }
  saving.value = true
  try {
    const updated = await payrollRulesetsApi.save(detail.value.ruleset_id, {
      reason: reason.value.trim(),
      row_version: detail.value.row_version,
      parameters,
    })
    detail.value = updated
    impactPreview.value = null
    activationPreviewConfirmed.value = false
    Object.keys(drafts).forEach(key => delete drafts[key])
    reason.value = ''
    diff.value = updated.has_default
      ? await payrollRulesetsApi.diff(updated.ruleset_id, 'default')
      : null
    await load()
    toast.success(t('payroll.rulesets.saved'))
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.save_failed')))
  } finally {
    saving.value = false
  }
}

async function runCommand(command: PayrollRulesetCommand | null) {
  if (!detail.value || command === null) return
  if (command === 'activate' && !canActivate.value) {
    toast.warning(t('payroll.rulesets.impact_preview.required'))
    return
  }
  if (reason.value.trim() === '') {
    toast.warning(t('payroll.rulesets.reason_required'))
    return
  }
  saving.value = true
  try {
    const result = await payrollRulesetsApi.command(detail.value.ruleset_id, command, {
      reason: reason.value.trim(),
      row_version: detail.value.row_version,
    })
    detail.value = result.ruleset
    impactPreview.value = null
    activationPreviewConfirmed.value = false
    reason.value = ''
    await load()
    toast.success(
      result.changed
        ? t(`payroll.rulesets.command_done.${command}`)
        : t('payroll.rulesets.command_noop'),
    )
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.command_failed')))
  } finally {
    saving.value = false
  }
}

async function loadImpactPreview() {
  if (!detail.value || detail.value.next_command !== 'activate') return
  impactPreview.value = null
  activationPreviewConfirmed.value = false
  impactPreviewLoading.value = true
  try {
    impactPreview.value = await payrollRulesetsApi.impactPreview(detail.value.ruleset_id)
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.impact_preview.load_failed')))
  } finally {
    impactPreviewLoading.value = false
  }
}

async function resetToDefault() {
  if (!detail.value) return
  if (!window.confirm(t('payroll.rulesets.reset_confirm'))) return
  saving.value = true
  try {
    const result = await payrollRulesetsApi.reset(
      detail.value.ruleset_id,
      reason.value.trim() || t('payroll.rulesets.reset_default_reason'),
    )
    await load()
    if (result.ruleset) {
      detail.value = result.ruleset
      impactPreview.value = null
      activationPreviewConfirmed.value = false
      diff.value = result.ruleset.has_default
        ? await payrollRulesetsApi.diff(result.ruleset.ruleset_id, 'default')
        : null
    } else {
      close()
    }
    Object.keys(drafts).forEach(key => delete drafts[key])
    reason.value = ''
    toast.success(t('payroll.rulesets.reset_done'))
  } catch (error: unknown) {
    toast.error(errorMessage(error, t('payroll.rulesets.save_failed')))
  } finally {
    saving.value = false
  }
}

function errorMessage(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { error?: { message?: string } } } })
    ?.response?.data?.error?.message
  return typeof message === 'string' && message !== '' ? message : fallback
}

function diffValue(entry: { type?: string; value?: unknown } | undefined): string {
  if (!entry) return '—'
  return displayValue({
    key: '',
    type: (entry.type ?? 'text') as PayrollRuleParameter['type'],
    value: (entry.value ?? null) as PayrollRuleParameter['value'],
    capability: null,
    note: null,
  })
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.rulesets.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.rulesets.subtitle') }}</p>
      </div>
      <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </header>

    <p
      v-if="!canEdit"
      class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
    >
      {{ t('payroll.rulesets.read_only_hint') }}
    </p>

    <p
      v-if="overview && !overview.override_storage_available"
      class="rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-600"
    >
      {{ t('payroll.rulesets.storage_unavailable') }}
    </p>

    <div
      v-if="overview?.degraded_reason"
      class="rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-500"
      data-test="ruleset-degraded"
    >
      <p data-test="ruleset-degraded-message">{{ t('payroll.rulesets.degraded') }}</p>
      <details class="mt-2 text-xs" data-test="ruleset-degraded-technical">
        <summary class="cursor-pointer">{{ t('payroll.rulesets.degraded_technical') }}</summary>
        <p class="mt-1 break-words font-mono">{{ overview.degraded_reason }}</p>
      </details>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <section
      v-for="group in overview?.domains ?? []"
      v-else
      :key="group.domain"
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      :data-test="`ruleset-domain-${group.domain}`"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t(`payroll.rulesets.domain.${group.domain}`) }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.rulesets.version_count', { count: group.version_count }) }}
          </p>
        </div>
        <span
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="domainStatusClass[domainStatus(group)]"
          :data-test="`ruleset-status-${group.domain}`"
        >
          {{ t(`payroll.rulesets.status.${domainStatus(group)}`) }}
        </span>
      </div>

      <p
        v-if="domainStatus(group) !== 'ready'"
        class="mt-2 max-w-3xl text-sm text-neutral-600"
        :data-test="`ruleset-status-hint-${group.domain}`"
      >
        {{ t(`payroll.rulesets.status_hint.${domainStatus(group)}`) }}
        <span v-if="group.manual_review_explanation">{{ group.manual_review_explanation }}</span>
      </p>

      <p
        v-if="manualReviewShare(group)"
        class="mt-1 text-xs text-neutral-500"
        :data-test="`ruleset-manual-share-${group.domain}`"
      >
        {{ manualReviewShare(group) }}
      </p>

      <ul v-if="group.coverage_issues.length" class="mt-3 space-y-1">
        <li
          v-for="issue in group.coverage_issues"
          :key="`${group.domain}-${issue.code}-${issue.message}`"
          class="rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-500"
        >
          {{ issue.message }}
        </li>
      </ul>

      <!--
        Odkud hodnoty jsou. Ne skryté v detailu: je to náhrada za zrušené
        schvalovací klikání, takže to musí být vidět rovnou u domény.
      -->
      <section
        v-if="domainSources(group).length"
        class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2"
        :data-test="`ruleset-provenance-${group.domain}`"
      >
        <h3 class="text-xs font-medium text-neutral-600">
          {{ t('payroll.rulesets.provenance.title') }}
        </h3>
        <ul class="mt-1 space-y-1">
          <li
            v-for="source in domainSources(group)"
            :key="`${group.domain}-${source.id}`"
            class="text-xs text-neutral-500"
          >
            <a
              v-if="source.url"
              :href="source.url"
              target="_blank"
              rel="noopener noreferrer"
              class="text-primary-700 underline decoration-dotted underline-offset-2"
            >{{ sourceLabel(source) }}</a>
            <span v-else>{{ sourceLabel(source) }}</span>
            <span v-if="source.retrieved_on" class="ml-1 whitespace-nowrap text-neutral-400">
              ({{ t('payroll.rulesets.provenance.retrieved', { date: source.retrieved_on }) }})
            </span>
          </li>
        </ul>
      </section>

      <!--
        Každá doména má vlastní tabulku, takže by si šířky sloupců počítala
        zvlášť a mezi kartami by se rozjely. `table-fixed` + shodný `colgroup`
        je srovná; `min-w` drží čitelnost a užší obrazovku řeší vodorovné
        rolování uvnitř karty, ne zúžení sloupců.
      -->
      <div class="mt-4 hidden md:block">
        <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[42rem] table-fixed divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <colgroup>
              <col v-if="tbl.isVisible('version')" class="w-[24%]">
              <col v-if="tbl.isVisible('effective')" class="w-[26%]">
              <col v-if="tbl.isVisible('lifecycle')" class="w-[21%]">
              <col v-if="tbl.isVisible('source')" class="w-[16%]">
              <col v-if="tbl.isVisible('actions')" class="w-[13%]">
            </colgroup>
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('version')" class="px-3 py-2">{{ t('payroll.rulesets.column.version') }}</th>
                <th v-if="tbl.isVisible('effective')" class="px-3 py-2">{{ t('payroll.rulesets.column.effective') }}</th>
                <th v-if="tbl.isVisible('lifecycle')" class="px-3 py-2">{{ t('payroll.rulesets.column.lifecycle') }}</th>
                <th v-if="tbl.isVisible('source')" class="px-3 py-2">{{ t('payroll.rulesets.column.source') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-3 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="version in group.versions" :key="version.ruleset_id">
                <td v-if="tbl.isVisible('version')" class="px-3 py-3">
                  <div class="font-medium text-neutral-900">{{ version.version }}</div>
                  <div class="text-xs break-all text-neutral-500">{{ version.ruleset_id }}</div>
                </td>
                <td v-if="tbl.isVisible('effective')" class="px-3 py-3 whitespace-nowrap text-neutral-600">
                  {{ version.effective_from }} – {{ version.effective_to }}
                </td>
                <td v-if="tbl.isVisible('lifecycle')" class="px-3 py-3">
                  <div class="flex flex-wrap items-center gap-1">
                    <span
                      class="rounded-full px-2 py-1 text-xs font-medium"
                      :class="lifecycleClass[version.lifecycle]"
                    >
                      {{ t(`payroll.rulesets.lifecycle.${version.lifecycle}`) }}
                    </span>
                    <span
                      v-if="!version.checksum_valid"
                      class="rounded-full bg-danger-50 px-2 py-1 text-xs font-medium text-danger-500"
                    >
                      {{ t('payroll.rulesets.checksum_invalid') }}
                    </span>
                  </div>
                </td>
                <td v-if="tbl.isVisible('source')" class="px-3 py-3 text-neutral-600">
                  {{ t(version.origin === 'customer_override'
                    ? 'payroll.rulesets.source.override'
                    : 'payroll.rulesets.source.builtin') }}
                </td>
                <td v-if="tbl.isVisible('actions')" class="px-3 py-3 text-right">
                  <button :class="btnOutlineSm('primary')" @click="open(version)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path :d="ICONS.edit" />
                    </svg>
                    {{ t('payroll.rulesets.open') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-3 md:hidden">
        <article
          v-for="version in group.versions"
          :key="version.ruleset_id"
          class="rounded-lg border border-neutral-200 p-3"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="font-medium text-neutral-900">{{ version.version }}</h3>
              <p class="mt-0.5 text-xs break-all text-neutral-500">{{ version.ruleset_id }}</p>
            </div>
            <span
              class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
              :class="lifecycleClass[version.lifecycle]"
            >
              {{ t(`payroll.rulesets.lifecycle.${version.lifecycle}`) }}
            </span>
          </div>
          <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
            <div>
              <dt class="text-neutral-500">{{ t('payroll.rulesets.column.effective') }}</dt>
              <dd class="mt-0.5 text-neutral-800">
                {{ version.effective_from }} – {{ version.effective_to }}
              </dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('payroll.rulesets.column.source') }}</dt>
              <dd class="mt-0.5 text-neutral-800">
                {{ t(version.origin === 'customer_override'
                  ? 'payroll.rulesets.source.override'
                  : 'payroll.rulesets.source.builtin') }}
              </dd>
            </div>
          </dl>
          <div class="mt-3 flex flex-wrap justify-end gap-2">
            <button :class="btnOutlineSm('primary')" @click="open(version)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.edit" />
              </svg>
              {{ t('payroll.rulesets.open') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <Modal
      v-if="detail"
      :title="`${t(`payroll.rulesets.domain.${detail.domain}`)} · ${detail.version}`"
      width-class="max-w-5xl"
      @close="close"
    >
      <div class="space-y-5">
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="lifecycleClass[detail.lifecycle]"
          >
            {{ t(`payroll.rulesets.lifecycle.${detail.lifecycle}`) }}
          </span>
          <span class="text-xs text-neutral-500">
            {{ detail.effective_from }} – {{ detail.effective_to }}
          </span>
          <span class="text-xs break-all text-neutral-400">{{ detail.ruleset_id }}</span>
        </div>

        <ul v-if="detail.blockers.length" class="space-y-1">
          <li
            v-for="issue in detail.blockers"
            :key="issue.code + issue.message"
            class="rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-500"
          >
            <strong>{{ t('payroll.rulesets.blocked_because') }}</strong> {{ issue.message }}
          </li>
        </ul>
        <ul v-if="detail.warnings.length" class="space-y-1">
          <li
            v-for="issue in detail.warnings"
            :key="issue.code + issue.message"
            class="rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-600"
          >
            {{ issue.message }}
          </li>
        </ul>

        <section
          v-if="canEdit && detail.next_command === 'activate'"
          class="rounded-lg border border-warning-500/40 bg-warning-50 p-4"
          data-test="ruleset-impact-preview"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-neutral-900">
                {{ t('payroll.rulesets.impact_preview.title') }}
              </h3>
              <p class="mt-1 max-w-3xl text-sm text-neutral-600">
                {{ t('payroll.rulesets.impact_preview.hint') }}
              </p>
            </div>
            <button
              type="button"
              :class="btnOutline('primary')"
              :disabled="saving || impactPreviewLoading"
              data-test="ruleset-impact-preview-load"
              @click="loadImpactPreview"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.cycle" />
              </svg>
              {{ impactPreviewLoading
                ? t('payroll.rulesets.impact_preview.loading')
                : t(impactPreview ? 'payroll.rulesets.impact_preview.refresh' : 'payroll.rulesets.impact_preview.load') }}
            </button>
          </div>

          <template v-if="impactPreview">
            <p class="mt-4 text-sm text-neutral-800" data-test="ruleset-impact-preview-effective">
              {{ t('payroll.rulesets.impact_preview.effective', impactPreview.effective) }}
            </p>
            <p v-if="impactPreview.baseline" class="mt-1 text-xs text-neutral-600">
              {{ t(`payroll.rulesets.impact_preview.baseline.${impactPreview.baseline.source}`, {
                version: impactPreview.baseline.version,
              }) }}
            </p>

            <div class="mt-3" data-test="ruleset-impact-preview-diff">
              <p v-if="!impactPreview.parameter_diff" class="text-sm text-neutral-600">
                {{ t('payroll.rulesets.impact_preview.baseline_unavailable') }}
              </p>
              <p v-else-if="impactPreview.parameter_diff.identical" class="text-sm text-neutral-600">
                {{ t('payroll.rulesets.impact_preview.identical') }}
              </p>
              <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[36rem] table-fixed divide-y divide-warning-500/20 text-sm">
                  <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                      <th class="px-3 py-2">{{ t('payroll.rulesets.column.parameter') }}</th>
                      <th class="px-3 py-2">{{ t('payroll.rulesets.column.before') }}</th>
                      <th class="px-3 py-2">{{ t('payroll.rulesets.column.after') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-warning-500/10">
                    <tr v-for="row in impactPreview.parameter_diff.changed" :key="`impact-c-${row.key}`">
                      <td class="px-3 py-2 align-top text-neutral-900">
                        {{ nameForKey(row.key) }}
                        <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                      </td>
                      <td class="px-3 py-2 align-top text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                      <td class="px-3 py-2 align-top font-medium text-neutral-900">{{ diffValue(row.after) }}</td>
                    </tr>
                    <tr v-for="row in impactPreview.parameter_diff.added" :key="`impact-a-${row.key}`">
                      <td class="px-3 py-2 align-top text-neutral-900">
                        {{ nameForKey(row.key) }}
                        <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                      </td>
                      <td class="px-3 py-2 align-top text-neutral-400">{{ t('payroll.rulesets.diff_added') }}</td>
                      <td class="px-3 py-2 align-top font-medium text-success-600">{{ diffValue(row.after) }}</td>
                    </tr>
                    <tr v-for="row in impactPreview.parameter_diff.removed" :key="`impact-r-${row.key}`">
                      <td class="px-3 py-2 align-top text-neutral-900">
                        {{ nameForKey(row.key) }}
                        <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                      </td>
                      <td class="px-3 py-2 align-top text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                      <td class="px-3 py-2 align-top text-danger-500">{{ t('payroll.rulesets.diff_removed') }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <p class="mt-3 text-sm text-neutral-700" data-test="ruleset-impact-preview-immutable">
              {{ t('payroll.rulesets.impact_preview.immutable') }}
            </p>
            <p class="mt-1 text-sm text-neutral-700" data-test="ruleset-impact-preview-money">
              {{ t('payroll.rulesets.impact_preview.money_unavailable') }}
            </p>
            <p v-if="!impactPreviewMatchesCandidate" class="mt-3 text-sm font-medium text-danger-500">
              {{ t('payroll.rulesets.impact_preview.stale') }}
            </p>
            <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm text-neutral-800">
              <input
                v-model="activationPreviewConfirmed"
                type="checkbox"
                class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                :disabled="!impactPreviewMatchesCandidate"
                data-test="ruleset-impact-preview-confirm"
              >
              <span>{{ t('payroll.rulesets.impact_preview.confirm') }}</span>
            </label>
          </template>
        </section>

        <nav class="flex flex-wrap gap-2 border-b border-neutral-200 pb-2">
          <button
            v-for="key in TABS"
            :key="key"
            type="button"
            class="cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
            :class="tab === key
              ? 'bg-primary-50 text-primary-700'
              : 'text-neutral-600 hover:bg-neutral-50'"
            @click="tab = key"
          >
            {{ t(`payroll.rulesets.tab.${key}`) }}
          </button>
        </nav>

        <div v-if="tab === 'parameters'" class="space-y-3">
          <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[52rem] table-fixed divide-y divide-neutral-200 text-sm">
              <colgroup>
                <col class="w-[30%]">
                <col class="w-[20%]">
                <col class="w-[28%]">
                <col class="w-[22%]">
              </colgroup>
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.parameter') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.value') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.note') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.rulesets.column.provenance') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="parameter in detail.parameters" :key="parameter.key" :data-test="`parameter-${parameter.key}`">
                  <td class="px-3 py-2 align-top">
                    <div class="font-medium text-neutral-900">{{ parameterName(parameter) }}</div>
                    <!-- Klíč je identifikátor v rulesetu i v auditní stopě, proto zůstává vidět. -->
                    <div class="mt-0.5 font-mono text-xs break-all text-neutral-400">{{ parameter.key }}</div>
                  </td>
                  <td class="px-3 py-2 align-top">
                    <template v-if="canEdit && isEditable(parameter)">
                      <div class="flex items-center gap-2">
                        <input
                          :value="draftFor(parameter)"
                          :type="parameter.type === 'text' ? 'text' : 'number'"
                          step="any"
                          class="h-8 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-900"
                          @input="setDraft(parameter, ($event.target as HTMLInputElement).value)"
                        >
                        <span class="text-xs whitespace-nowrap text-neutral-500">{{ unitLabel(parameter) }}</span>
                      </div>
                      <!-- U výčtu se edituje kód, ale co znamená musí být vidět i při editaci. -->
                      <p v-if="parameter.value_label" class="mt-1 text-xs text-neutral-500">
                        {{ parameter.value_label }}
                      </p>
                    </template>
                    <span
                      v-else-if="isManualReview(parameter)"
                      class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600"
                    >
                      {{ t('payroll.rulesets.manual_review_badge') }}
                    </span>
                    <span v-else class="text-neutral-700">{{ displayValue(parameter) }}</span>
                  </td>
                  <td class="px-3 py-2 align-top text-xs text-neutral-500">
                    <template v-if="parameter.manual_review_why || parameter.manual_review_action">
                      <p v-if="parameter.manual_review_why">
                        <strong class="font-medium text-neutral-600">{{ t('payroll.rulesets.manual_review_why') }}</strong>
                        {{ parameter.manual_review_why }}
                      </p>
                      <p v-if="parameter.manual_review_action" class="mt-1">
                        <strong class="font-medium text-neutral-600">{{ t('payroll.rulesets.manual_review_action') }}</strong>
                        {{ parameter.manual_review_action }}
                      </p>
                    </template>
                    <template v-else>{{ parameter.note ?? '' }}</template>
                  </td>
                  <td class="px-3 py-2 align-top text-xs text-neutral-500">
                    <template v-if="singleParameterSource">
                      <a
                        v-if="singleParameterSource.url"
                        :href="singleParameterSource.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary-700 underline decoration-dotted underline-offset-2"
                      >{{ sourceLabel(singleParameterSource) }}</a>
                      <span v-else>{{ sourceLabel(singleParameterSource) }}</span>
                      <span v-if="singleParameterSource.retrieved_on" class="block text-neutral-400">
                        {{ t('payroll.rulesets.provenance.retrieved', {
                          date: singleParameterSource.retrieved_on,
                        }) }}
                      </span>
                    </template>
                    <button
                      v-else
                      type="button"
                      class="cursor-pointer text-primary-700 underline decoration-dotted underline-offset-2"
                      @click="tab = 'sources'"
                    >
                      {{ t('payroll.rulesets.provenance.multiple', {
                        count: detail.sources.length,
                      }) }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <article
            v-for="parameter in detail.parameters"
            :key="`m-${parameter.key}`"
            class="rounded-lg border border-neutral-200 p-3 md:hidden"
          >
            <h4 class="text-sm font-medium text-neutral-900">{{ parameterName(parameter) }}</h4>
            <p class="mt-0.5 font-mono text-xs break-all text-neutral-400">{{ parameter.key }}</p>
            <div v-if="canEdit && isEditable(parameter)" class="mt-2 flex items-center gap-2">
              <input
                :value="draftFor(parameter)"
                :type="parameter.type === 'text' ? 'text' : 'number'"
                step="any"
                class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-900"
                @input="setDraft(parameter, ($event.target as HTMLInputElement).value)"
              >
              <span class="text-xs whitespace-nowrap text-neutral-500">{{ unitLabel(parameter) }}</span>
            </div>
            <p
              v-else-if="isManualReview(parameter)"
              class="mt-2 inline-block rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600"
            >
              {{ t('payroll.rulesets.manual_review_badge') }}
            </p>
            <p v-else class="mt-1 text-sm text-neutral-700">{{ displayValue(parameter) }}</p>
            <template v-if="parameter.manual_review_why || parameter.manual_review_action">
              <p v-if="parameter.manual_review_why" class="mt-1 text-xs text-neutral-500">
                <strong class="font-medium text-neutral-600">{{ t('payroll.rulesets.manual_review_why') }}</strong>
                {{ parameter.manual_review_why }}
              </p>
              <p v-if="parameter.manual_review_action" class="mt-1 text-xs text-neutral-500">
                <strong class="font-medium text-neutral-600">{{ t('payroll.rulesets.manual_review_action') }}</strong>
                {{ parameter.manual_review_action }}
              </p>
            </template>
            <p v-else-if="parameter.note" class="mt-1 text-xs text-neutral-500">{{ parameter.note }}</p>
            <p v-if="singleParameterSource" class="mt-1 text-xs text-neutral-500">
              <strong class="font-medium text-neutral-600">
                {{ t('payroll.rulesets.column.provenance') }}
              </strong>
              <a
                v-if="singleParameterSource.url"
                :href="singleParameterSource.url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-700 underline decoration-dotted underline-offset-2"
              >{{ sourceLabel(singleParameterSource) }}</a>
              <span v-else>{{ sourceLabel(singleParameterSource) }}</span>
            </p>
          </article>
        </div>

        <!--
          Doložení, ne razítko. Zákazník sazby neschvaluje — tady vidí, o co se
          dodaná hodnota opírá a kdy jsme to naposledy ověřovali.
        -->
        <div v-else-if="tab === 'sources'" class="space-y-4" data-test="ruleset-sources-tab">
          <p class="text-sm text-neutral-600">
            {{ t(detail.origin === 'customer_override'
              ? 'payroll.rulesets.provenance.override_note'
              : 'payroll.rulesets.provenance.vendor_note') }}
          </p>

          <ul class="space-y-2">
            <li
              v-for="source in detail.sources"
              :key="source.id ?? source.url ?? ''"
              class="rounded-lg border border-neutral-200 p-3"
            >
              <a
                v-if="source.url"
                :href="source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm font-medium text-primary-700 underline decoration-dotted underline-offset-2 break-words"
              >{{ sourceLabel(source) }}</a>
              <span v-else class="text-sm font-medium text-neutral-900">{{ sourceLabel(source) }}</span>
              <p v-if="source.url" class="mt-0.5 text-xs break-all text-neutral-400">{{ source.url }}</p>
              <p v-if="source.retrieved_on" class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.rulesets.provenance.retrieved', { date: source.retrieved_on }) }}
              </p>
            </li>
          </ul>

          <div v-if="detail.technical_review" class="rounded-lg border border-neutral-200 p-3">
            <h4 class="text-sm font-medium text-neutral-900">
              {{ t('payroll.rulesets.provenance.technical_review') }}
            </h4>
            <p v-if="detail.technical_review.evidence" class="mt-1 text-xs text-neutral-600">
              {{ detail.technical_review.evidence }}
            </p>
            <p class="mt-1 text-xs text-neutral-400">
              {{ t('payroll.rulesets.provenance.checked', {
                by: detail.technical_review.checked_by ?? '—',
                on: detail.technical_review.checked_on ?? '—',
              }) }}
            </p>
          </div>

          <div v-if="detail.approval" class="rounded-lg border border-neutral-200 p-3">
            <h4 class="text-sm font-medium text-neutral-900">
              {{ t('payroll.rulesets.provenance.approval') }}
            </h4>
            <p v-if="detail.approval.evidence" class="mt-1 text-xs text-neutral-600">
              {{ detail.approval.evidence }}
            </p>
            <p class="mt-1 text-xs text-neutral-400">
              {{ t('payroll.rulesets.provenance.approved', {
                by: detail.approval.approved_by ?? '—',
                on: detail.approval.approved_on ?? '—',
              }) }}
            </p>
          </div>
          <p v-else class="text-xs text-neutral-500">
            {{ t('payroll.rulesets.provenance.no_approval') }}
          </p>
        </div>

        <div v-else-if="tab === 'diff'" class="space-y-3">
          <p v-if="!diff" class="text-sm text-neutral-500">
            {{ t('payroll.rulesets.diff_unavailable') }}
          </p>
          <template v-else>
            <p class="text-sm text-neutral-500">
              {{ t('payroll.rulesets.diff_hint', {
                unchanged: diff.parameters.unchanged_count,
              }) }}
            </p>
            <p v-if="diff.parameters.identical" class="text-sm text-neutral-600">
              {{ t('payroll.rulesets.diff_identical') }}
            </p>
            <div v-else class="overflow-x-auto">
              <table class="w-full min-w-[40rem] table-fixed divide-y divide-neutral-200 text-sm">
                <colgroup>
                  <col class="w-[44%]">
                  <col class="w-[28%]">
                  <col class="w-[28%]">
                </colgroup>
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.parameter') }}</th>
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.before') }}</th>
                    <th class="px-3 py-2">{{ t('payroll.rulesets.column.after') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in diff.parameters.changed" :key="`c-${row.key}`">
                    <td class="px-3 py-2 align-top text-neutral-900">
                      {{ nameForKey(row.key) }}
                      <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                    </td>
                    <td class="px-3 py-2 align-top text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                    <td class="px-3 py-2 align-top font-medium text-neutral-900">{{ diffValue(row.after) }}</td>
                  </tr>
                  <tr v-for="row in diff.parameters.added" :key="`a-${row.key}`">
                    <td class="px-3 py-2 align-top text-neutral-900">
                      {{ nameForKey(row.key) }}
                      <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                    </td>
                    <td class="px-3 py-2 align-top text-neutral-400">{{ t('payroll.rulesets.diff_added') }}</td>
                    <td class="px-3 py-2 align-top font-medium text-success-600">{{ diffValue(row.after) }}</td>
                  </tr>
                  <tr v-for="row in diff.parameters.removed" :key="`r-${row.key}`">
                    <td class="px-3 py-2 align-top text-neutral-900">
                      {{ nameForKey(row.key) }}
                      <span class="mt-0.5 block font-mono text-xs break-all text-neutral-400">{{ row.key }}</span>
                    </td>
                    <td class="px-3 py-2 align-top text-neutral-500 line-through">{{ diffValue(row.before) }}</td>
                    <td class="px-3 py-2 align-top text-danger-500">{{ t('payroll.rulesets.diff_removed') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>

        <div v-else class="space-y-2">
          <p v-if="!detail.audit.length" class="text-sm text-neutral-500">
            {{ t('payroll.rulesets.audit_empty') }}
          </p>
          <article
            v-for="row in detail.audit"
            :key="row.id"
            class="rounded-lg border border-neutral-200 p-3 text-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-neutral-900">
                {{ t(`payroll.rulesets.action.${row.action}`) }}
              </span>
              <span class="text-xs text-neutral-500">{{ row.created_at }}</span>
            </div>
            <p class="mt-1 text-neutral-600">{{ row.reason }}</p>
            <p class="mt-1 text-xs text-neutral-400">
              {{ t('payroll.rulesets.audit_actor', { user: row.actor_user_id ?? '—' }) }}
            </p>
          </article>
        </div>

        <div v-if="canEdit" class="space-y-3 border-t border-neutral-200 pt-4">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.rulesets.reason_label') }}
            </span>
            <input
              v-model="reason"
              type="text"
              maxlength="1000"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              :placeholder="t('payroll.rulesets.reason_placeholder')"
              data-test="ruleset-reason"
            >
          </label>

          <div class="flex flex-wrap justify-end gap-2">
            <button
              v-if="detail.is_override"
              :class="btnOutline('warning')"
              :disabled="saving"
              @click="resetToDefault"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.uturn" />
              </svg>
              {{ t('payroll.rulesets.reset') }}
            </button>
            <button
              v-if="detail.next_command"
              :class="detail.next_command === 'activate' ? btnFilled('success') : btnOutline('success')"
              :disabled="saving || detail.blockers.length > 0 || (detail.next_command === 'activate' && !canActivate)"
              :title="detail.blockers[0]?.message ?? (detail.next_command === 'activate' && !canActivate
                ? t('payroll.rulesets.impact_preview.required')
                : undefined)"
              :data-test="`ruleset-command-${detail.next_command}`"
              @click="runCommand(detail.next_command)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.badgeCheck" />
              </svg>
              {{ t(`payroll.rulesets.command.${detail.next_command}`) }}
            </button>
            <button :class="btnFilled('primary')" :disabled="saving || !hasChanges" @click="save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.check" />
              </svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>

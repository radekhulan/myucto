<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollJmhzMunicipalityOption,
  type PayrollRelationType,
  type PayrollTermsBulkChanges,
  type PayrollTermsBulkMode,
  type PayrollTermsBulkPreview,
  type PayrollTermsBulkResult,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import CzIscoPicker from '@/components/payroll/CzIscoPicker.vue'
import { btnFilled, btnOutline, BTN_DISABLED_NOTE, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

/*
 * Hromadná úprava podmínek vybraných vztahů (typicky stovka brigádníků na
 * DPP). Zapisuje se OPRAVA platné verze podmínek, stejně jako na kartě vztahu;
 * vztahy, u kterých by karta chtěla novou verzi nebo které drží schválená mzda,
 * server vynechá s důvodem a odkazem na kartu.
 */
const props = defineProps<{
  /** První den měsíce, jehož vztahy se nabídnou (YYYY-MM-DD). */
  periodStart: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollTermsBulkResult): void
}>()

const { t } = useI18n()
const toast = useToast()

const RELATION_TYPES: PayrollRelationType[] = ['dpp', 'dpc', 'employment', 'small_scale_employment', 'statutory_body']
const BASIS_KINDS = ['agreement', 'wage_statement', 'contract', 'internal_regulation', 'collective_agreement']

const period = ref(props.periodStart.slice(0, 7))
const relationType = ref<PayrollRelationType | ''>('dpp')
const preview = ref<PayrollTermsBulkPreview | null>(null)
const loading = ref(false)
const loadError = ref('')
const selected = ref<Set<number>>(new Set())

const mode = ref<PayrollTermsBulkMode>('fill')
const hourlyRate = ref('')
const basisKind = ref('')
const basis = ref('')
const probableEarning = ref('')
const probableRationale = ref('')
const czIscoCode = ref<string | null>(null)
const activityCode = ref('')
const municipalityCode = ref<string | null>(null)
const municipalityName = ref<string | null>(null)
const countryCode = ref('CZ')
const municipalityOptions = ref<PayrollJmhzMunicipalityOption[]>([])
const municipalitiesLoading = ref(false)
const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)

const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollTermsBulkResult | null>(null)

const periodStart = computed(() => `${period.value}-01`)
const editableItems = computed(() => preview.value?.items.filter(item => item.state === 'editable') ?? [])
const excludedItems = computed(() => preview.value?.items.filter(item => item.state === 'excluded') ?? [])
const allSelected = computed(() =>
  editableItems.value.length > 0 && editableItems.value.every(item => selected.value.has(item.employment_id)))
const selectedMunicipality = computed(() => municipalityCode.value && municipalityName.value
  ? { value: municipalityCode.value, label: municipalityName.value, secondary: municipalityCode.value }
  : null)

/** Částka v korunách („250" i „250,50") na haléře; `null` = nevyplněno, NaN = neplatné. */
function toMinor(value: string): number | null {
  const normalized = value.replace(/\s/g, '').replace(',', '.')
  if (normalized === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) return Number.NaN
  return Math.round(Number(normalized) * 100)
}

function formatMinor(value: number | null): string {
  if (value === null) return '—'
  return (value / 100).toLocaleString('cs-CZ', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

const changes = computed<PayrollTermsBulkChanges | null>(() => {
  const payload: PayrollTermsBulkChanges = {}
  const rate = toMinor(hourlyRate.value)
  if (rate !== null) {
    if (Number.isNaN(rate) || rate <= 0) return null
    payload.hourly_rate_minor = rate
    if (basisKind.value) payload.remuneration_basis_kind = basisKind.value
    if (basis.value.trim()) payload.remuneration_basis = basis.value.trim()
  }
  const earning = toMinor(probableEarning.value)
  if (earning !== null) {
    if (Number.isNaN(earning) || earning <= 0) return null
    payload.probable_hourly_earning_minor = earning
    payload.probable_earning_rationale = probableRationale.value.trim()
  }
  if (czIscoCode.value) payload.cz_isco_code = czIscoCode.value
  if (activityCode.value) payload.activity_code = activityCode.value
  if (municipalityCode.value) {
    payload.jmhz_workplace_municipality_code = municipalityCode.value
    payload.jmhz_workplace_country_code = countryCode.value
  }
  return payload
})

const applyBlockedReason = computed(() => {
  if (!preview.value) return ''
  if (selected.value.size === 0) return t('payroll.terms_bulk.blocked.no_selection')
  if (changes.value === null) return t('payroll.terms_bulk.blocked.invalid_amount')
  if (Object.keys(changes.value).length === 0) return t('payroll.terms_bulk.blocked.no_change')
  if (changes.value.probable_hourly_earning_minor !== undefined && !changes.value.probable_earning_rationale) {
    return t('payroll.terms_bulk.blocked.rationale_required')
  }
  return ''
})

async function loadPreview() {
  if (!/^\d{4}-\d{2}$/.test(period.value)) return
  loading.value = true
  loadError.value = ''
  result.value = null
  applyError.value = ''
  try {
    preview.value = await payrollApi.termsBulkPreview({
      period_start: periodStart.value,
      relation_type: relationType.value || null,
      employment_ids: null,
    })
    selected.value = new Set(preview.value.editable_employment_ids)
    if (!preview.value.activity_codes.some(option => option.code === activityCode.value)) activityCode.value = ''
  } catch (error) {
    preview.value = null
    loadError.value = apiErrorMessage(error, t('payroll.terms_bulk.load_failed'))
  } finally {
    loading.value = false
  }
}

function toggle(employmentId: number) {
  const next = new Set(selected.value)
  if (next.has(employmentId)) next.delete(employmentId)
  else next.add(employmentId)
  selected.value = next
}

function toggleAll() {
  selected.value = allSelected.value
    ? new Set()
    : new Set(editableItems.value.map(item => item.employment_id))
}

async function searchMunicipalities(query: string) {
  if (query.trim().length < 2) {
    municipalityOptions.value = []
    return
  }
  municipalitiesLoading.value = true
  try {
    municipalityOptions.value = await payrollApi.searchJmhzMunicipalities(query)
  } catch {
    municipalityOptions.value = []
  } finally {
    municipalitiesLoading.value = false
  }
}

function selectMunicipality(code: string | null) {
  const option = municipalityOptions.value.find(candidate => candidate.code === code)
  municipalityCode.value = option?.code ?? null
  municipalityName.value = option?.label ?? null
}

async function apply() {
  if (!preview.value || !changes.value || applyBlockedReason.value || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.termsBulkApply({
      period_start: periodStart.value,
      mode: mode.value,
      changes: changes.value,
      employment_ids: editableItems.value
        .map(item => item.employment_id)
        .filter(id => selected.value.has(id)),
    })
    result.value = response
    toast.success(t('payroll.terms_bulk.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.terms_bulk.apply_failed'))
  } finally {
    applying.value = false
  }
}

function cardLink(employmentId: number) {
  return { name: 'payroll-people', query: { employment: String(employmentId), panel: 'employment_terms' } }
}

/** „2026-02-01" → „02/2026". */
function monthLabel(date: string): string {
  return `${date.slice(5, 7)}/${date.slice(0, 4)}`
}

function personName(item: { employment_id: number, full_name: string | null }): string {
  return item.full_name ?? String(item.employment_id)
}

function reasonLabel(reason: string | null, lockedPeriod: string | null = null): string {
  if (!reason) return ''
  return t(`payroll.terms_bulk.reason.${reason}`, { period: lockedPeriod ? lockedPeriod.slice(0, 7) : '' })
}

watch([period, relationType], () => { void loadPreview() })

onMounted(async () => {
  void loadPreview()
  try {
    jmhzOptions.value = await payrollApi.employmentJmhzEvidenceOptions()
  } catch {
    jmhzOptions.value = null
  }
})
</script>

<template>
  <Modal :title="t('payroll.terms_bulk.title')" width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4" data-test="terms-bulk-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.terms_bulk.intro') }}</p>

      <div v-if="!result" class="grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.period') }}</span>
          <input
            v-model="period"
            type="month"
            :disabled="applying"
            data-test="terms-bulk-period"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.relation_type') }}</span>
          <select
            v-model="relationType"
            :disabled="applying"
            data-test="terms-bulk-relation"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
          >
            <option value="">{{ t('payroll.terms_bulk.relation_all') }}</option>
            <option v-for="type in RELATION_TYPES" :key="type" :value="type">{{ t(`payroll.people.relations.${type}`) }}</option>
          </select>
        </label>
      </div>

      <div v-if="loading" class="space-y-2" data-test="terms-bulk-loading">
        <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      >
        <p>{{ loadError }}</p>
        <button type="button" class="whitespace-nowrap" :class="btnOutline('danger')" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.terms_bulk.retry') }}
        </button>
      </div>

      <template v-else-if="preview && !result">
        <div class="grid grid-cols-3 gap-3">
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.terms_bulk.summary.employments') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.employments }}</p>
          </article>
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.terms_bulk.summary.selected') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="terms-bulk-selected">{{ selected.size }} / {{ preview.summary.editable }}</p>
          </article>
          <article class="rounded-lg p-3" :class="preview.summary.excluded ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="preview.summary.excluded ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.terms_bulk.summary.excluded') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.excluded }}</p>
          </article>
        </div>

        <div v-if="editableItems.length" class="rounded-lg border border-neutral-200">
          <label class="flex items-center gap-2 border-b border-neutral-200 px-3 py-2 text-xs font-medium text-neutral-600">
            <input type="checkbox" :checked="allSelected" data-test="terms-bulk-toggle-all" @change="toggleAll">
            {{ t('payroll.terms_bulk.select_all') }}
          </label>
          <ul class="max-h-56 divide-y divide-neutral-100 overflow-y-auto text-sm" data-test="terms-bulk-items">
            <li v-for="item in editableItems" :key="item.employment_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-1.5">
              <label class="flex min-w-0 items-center gap-2">
                <input
                  type="checkbox"
                  :checked="selected.has(item.employment_id)"
                  :data-test="`terms-bulk-item-${item.employment_id}`"
                  @change="toggle(item.employment_id)"
                >
                <span class="truncate font-medium">{{ item.full_name }}</span>
                <span class="text-xs text-neutral-500">{{ item.employment_code }} · {{ t(`payroll.people.relations.${item.relation_type}`) }}</span>
              </label>
              <span
                v-if="item.new_version_from"
                class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700"
                :data-test="`terms-bulk-new-version-${item.employment_id}`"
              >{{ t('payroll.terms_bulk.new_version_badge', { month: monthLabel(item.new_version_from) }) }}</span>
              <span class="ml-auto text-xs text-neutral-500">
                {{ t('payroll.terms_bulk.current', {
                  rate: formatMinor(item.hourly_rate_minor),
                  isco: item.cz_isco_code ?? '—',
                  activity: item.activity_code ?? '—',
                }) }}
              </span>
            </li>
          </ul>
        </div>
        <p v-else class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-600">{{ t('payroll.terms_bulk.nothing_editable') }}</p>

        <details
          v-if="excludedItems.length"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          data-test="terms-bulk-excluded"
        >
          <summary class="cursor-pointer font-medium">{{ t('payroll.terms_bulk.excluded_title', { count: excludedItems.length }) }}</summary>
          <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
            <li v-for="item in excludedItems" :key="item.employment_id">
              <RouterLink :to="cardLink(item.employment_id)" class="font-medium underline" @click="emit('close')">{{ item.full_name }}</RouterLink>:
              {{ reasonLabel(item.reason, item.locked_period) }}
            </li>
          </ul>
        </details>

        <fieldset class="space-y-2">
          <legend class="mb-1 text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.mode') }}</legend>
          <div class="flex flex-wrap gap-4 text-sm">
            <label class="flex items-center gap-2">
              <input v-model="mode" type="radio" value="fill" data-test="terms-bulk-mode-fill">
              {{ t('payroll.terms_bulk.mode_fill') }}
            </label>
            <label class="flex items-center gap-2">
              <input v-model="mode" type="radio" value="overwrite" data-test="terms-bulk-mode-overwrite">
              {{ t('payroll.terms_bulk.mode_overwrite') }}
            </label>
          </div>
          <p v-if="mode === 'overwrite'" class="rounded-lg border border-warning-200 bg-warning-50 p-2 text-xs text-warning-800">
            {{ t('payroll.terms_bulk.overwrite_warning') }}
          </p>
        </fieldset>

        <p class="text-xs text-neutral-500">{{ t('payroll.terms_bulk.fields_hint') }}</p>

        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.hourly_rate') }}</span>
            <input
              v-model="hourlyRate"
              inputmode="decimal"
              :disabled="applying"
              data-test="terms-bulk-hourly-rate"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.basis_kind') }}</span>
            <select
              v-model="basisKind"
              :disabled="applying || !hourlyRate"
              data-test="terms-bulk-basis-kind"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
              <option value="">{{ t('payroll.terms_bulk.basis_keep') }}</option>
              <option v-for="kind in BASIS_KINDS" :key="kind" :value="kind">{{ t(`payroll.people.remuneration.basis_kinds.${kind}`) }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.basis') }}</span>
            <input
              v-model="basis"
              :disabled="applying || !hourlyRate"
              :placeholder="t('payroll.terms_bulk.basis_placeholder')"
              data-test="terms-bulk-basis"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
          </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.probable_earning') }}</span>
            <input
              v-model="probableEarning"
              inputmode="decimal"
              :disabled="applying"
              data-test="terms-bulk-probable"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
          </label>
          <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.probable_rationale') }}</span>
            <input
              v-model="probableRationale"
              :disabled="applying || !probableEarning"
              :placeholder="t('payroll.terms_bulk.probable_rationale_placeholder')"
              data-test="terms-bulk-rationale"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
          </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.cz_isco') }}</span>
            <CzIscoPicker v-model="czIscoCode" :disabled="applying" />
          </div>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.activity_code') }}</span>
            <select
              v-model="activityCode"
              :disabled="applying || !preview.activity_codes.length"
              data-test="terms-bulk-activity"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
              <option value="">{{ t('payroll.terms_bulk.no_change') }}</option>
              <option v-for="option in preview.activity_codes" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
            </select>
            <span v-if="!preview.activity_codes.length" class="mt-1 block text-xs text-neutral-500">{{ t('payroll.terms_bulk.activity_needs_relation') }}</span>
          </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.municipality') }}</span>
            <SearchableSelect
              :model-value="municipalityCode"
              :options="municipalityOptions.map(option => ({ value: option.code, label: option.label, secondary: option.code }))"
              :selected-option="selectedMunicipality"
              :remote="true"
              :disabled="applying"
              :loading="municipalitiesLoading"
              :placeholder="t('payroll.terms_bulk.search_municipality')"
              accent="payroll"
              data-test="terms-bulk-municipality"
              @search="searchMunicipalities"
              @update:model-value="selectMunicipality"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.terms_bulk.country') }}</span>
            <select
              v-model="countryCode"
              :disabled="applying || !municipalityCode"
              data-test="terms-bulk-country"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
              <option value="CZ">CZ</option>
              <option v-for="country in (jmhzOptions?.countries ?? []).filter(option => option.code !== 'CZ')" :key="country.code" :value="country.code">
                {{ country.code }} · {{ country.label }}
              </option>
            </select>
          </label>
        </div>

        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
          {{ applyError }}
        </p>

        <div class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :disabled="applying" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="cursor-pointer whitespace-nowrap"
              :class="btnFilled('primary')"
              :disabled="applying || applyBlockedReason !== ''"
              :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)"
              data-test="terms-bulk-apply"
              @click="apply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ applying ? t('common.saving') : t('payroll.terms_bulk.apply', { count: selected.size }) }}
            </button>
            <p v-if="applyBlockedReason" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
          </div>
        </div>
      </template>

      <section v-if="result" data-test="terms-bulk-result" class="space-y-3">
        <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.terms_bulk.result_title') }}</h3>
        <div class="grid grid-cols-3 gap-3">
          <article class="rounded-lg bg-success-50 p-3">
            <p class="text-xs text-success-700">{{ t('payroll.terms_bulk.result.applied') }}</p>
            <p class="mt-1 text-lg font-semibold text-success-700" data-test="terms-bulk-applied">{{ result.counts.applied }}</p>
          </article>
          <article class="rounded-lg p-3" :class="result.counts.skipped ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="result.counts.skipped ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.terms_bulk.result.skipped') }}</p>
            <p class="mt-1 text-lg font-semibold" data-test="terms-bulk-skipped">{{ result.counts.skipped }}</p>
          </article>
          <article class="rounded-lg p-3" :class="result.counts.failed ? 'bg-danger-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="result.counts.failed ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.terms_bulk.result.failed') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ result.counts.failed }}</p>
          </article>
        </div>
        <ul class="max-h-64 space-y-1 overflow-y-auto text-xs" data-test="terms-bulk-rows">
          <li v-for="item in result.applied" :key="`a${item.employment_id}`" class="text-success-700">
            <span class="font-medium">{{ personName(item) }}</span>:
            {{ item.kind === 'new_version'
              ? t('payroll.terms_bulk.row_new_version', { month: monthLabel(item.effective_from) })
              : t('payroll.terms_bulk.row_correction') }}
          </li>
          <li v-for="item in result.skipped" :key="`s${item.employment_id}`" class="text-warning-800">
            <RouterLink :to="cardLink(item.employment_id)" class="font-medium underline" @click="emit('close')">{{ personName(item) }}</RouterLink>:
            {{ reasonLabel(item.reason) }}
          </li>
          <li v-for="item in result.failed" :key="`f${item.employment_id}`" class="text-danger-700">
            <RouterLink :to="cardLink(item.employment_id)" class="font-medium underline" @click="emit('close')">{{ personName(item) }}</RouterLink>:
            {{ item.message }}
          </li>
        </ul>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" data-test="terms-bulk-close" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </section>
    </div>
  </Modal>
</template>

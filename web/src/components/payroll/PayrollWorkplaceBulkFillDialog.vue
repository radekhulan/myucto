<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollJmhzMunicipalityOption,
  type PayrollWorkplaceBulkPreview,
  type PayrollWorkplaceBulkResult,
  type PayrollWorkplaceBulkUnverified,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, BTN_DISABLED_NOTE, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

/*
 * Hromadné doplnění místa výkonu práce pro JMHZ. Místo výkonu práce je
 * SJEDNANÉ v pracovní smlouvě — aplikace ho nemůže odhadnout, proto dialog
 * jen navrhne nejčastější OVĚŘENÉ pracoviště ve firmě a účetní ho potvrdí
 * nebo zvolí jiné. Náhled nic nezapisuje a existující (ověřené) pracoviště
 * se nikdy nepřepisuje — oprava jde jen na kartě konkrétního vztahu.
 */
const props = defineProps<{
  /** První den měsíce, od kterého se pracoviště doplní (YYYY-MM-DD). */
  periodStart: string
  /** `null` = všechny pracovní vztahy. */
  employmentIds?: number[] | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollWorkplaceBulkResult): void
}>()

defineSlots<{
  'after-apply'?(props: { result: PayrollWorkplaceBulkResult }): unknown
}>()

const { t } = useI18n()
const toast = useToast()

const preview = ref<PayrollWorkplaceBulkPreview | null>(null)
const loading = ref(false)
const loadError = ref('')
const applying = ref(false)
const applyError = ref('')
const result = ref<PayrollWorkplaceBulkResult | null>(null)

const municipalityCode = ref<string | null>(null)
const municipalityName = ref<string | null>(null)
const countryCode = ref('CZ')
const municipalityOptions = ref<PayrollJmhzMunicipalityOption[]>([])
const municipalitiesLoading = ref(false)
const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)

const selectedMunicipality = computed(() => municipalityCode.value && municipalityName.value
  ? { value: municipalityCode.value, label: municipalityName.value, secondary: municipalityCode.value }
  : null)

const applyCount = computed(() => preview.value?.missing_employment_ids.length ?? 0)
const applyBlockedReason = computed(() => {
  if (!preview.value) return ''
  if (applyCount.value === 0) return t('payroll.workplace_bulk.nothing_to_apply')
  if (!municipalityCode.value) return t('payroll.workplace_bulk.municipality_required')
  return ''
})

async function loadPreview() {
  loading.value = true
  loadError.value = ''
  result.value = null
  applyError.value = ''
  try {
    preview.value = await payrollApi.workplaceBulkPreview({
      period_start: props.periodStart,
      employment_ids: props.employmentIds ?? null,
    })
    const firstSuggestion = preview.value.suggestions[0]
    if (firstSuggestion) {
      municipalityCode.value = firstSuggestion.municipality_code
      municipalityName.value = firstSuggestion.municipality_name
      countryCode.value = firstSuggestion.country_code
    }
  } catch (error) {
    preview.value = null
    loadError.value = apiErrorMessage(error, t('payroll.workplace_bulk.load_failed'))
  } finally {
    loading.value = false
  }
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
  const selected = municipalityOptions.value.find(option => option.code === code)
  municipalityCode.value = selected?.code ?? null
  municipalityName.value = selected?.label ?? null
}

async function apply() {
  if (!preview.value || !municipalityCode.value || applyCount.value === 0 || applying.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await payrollApi.workplaceBulkApply({
      period_start: props.periodStart,
      municipality_code: municipalityCode.value,
      country_code: countryCode.value,
      employment_ids: preview.value.missing_employment_ids,
    })
    result.value = response
    toast.success(t('payroll.workplace_bulk.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    applyError.value = apiErrorMessage(error, t('payroll.workplace_bulk.apply_failed'))
  } finally {
    applying.value = false
  }
}

/*
 * Vyplněné, ale neověřené pracoviště („Praha") se hromadně nepřepisuje.
 * Musí být ale vidět jménem i po uložení, jinak účetní neví, proč JMHZ
 * pracoviště dál hlásí jako chybějící.
 */
const unverifiedRows = computed<PayrollWorkplaceBulkUnverified[]>(() => {
  const rows = new Map<number, PayrollWorkplaceBulkUnverified>()
  for (const row of [...(preview.value?.unverified ?? []), ...(result.value?.unverified ?? [])]) {
    rows.set(row.employment_id, row)
  }
  return [...rows.values()]
})

function cardLink(row: PayrollWorkplaceBulkUnverified) {
  return {
    name: 'payroll-people',
    query: { person: String(row.employee_id), employment: String(row.employment_id), panel: 'employment_terms' },
  }
}

function itemLabel(item: { employment_id: number }): string {
  const found = preview.value?.items.find(candidate => candidate.employment_id === item.employment_id)
  return found?.full_name ?? String(item.employment_id)
}

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
  <Modal :title="t('payroll.workplace_bulk.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4" data-test="workplace-bulk-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.workplace_bulk.intro') }}</p>

      <div v-if="loading" class="space-y-2" data-test="workplace-bulk-loading">
        <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutline('danger')" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.workplace_bulk.retry') }}
        </button>
      </div>

      <div
        v-if="!loading && !loadError && unverifiedRows.length"
        class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
        data-test="workplace-bulk-unverified"
      >
        <p class="font-medium">{{ t('payroll.workplace_bulk.unverified_title', { count: unverifiedRows.length }) }}</p>
        <p class="mt-1 text-xs">{{ t('payroll.workplace_bulk.unverified_hint') }}</p>
        <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-xs">
          <li v-for="row in unverifiedRows" :key="row.employment_id" :data-test="`workplace-bulk-unverified-${row.employment_id}`">
            <RouterLink :to="cardLink(row)" class="font-medium underline" @click="emit('close')">{{ row.full_name }}</RouterLink>
            <span v-if="row.employment_code"> · {{ row.employment_code }}</span>:
            {{ t('payroll.workplace_bulk.unverified_value', { place: row.work_place ?? '—', code: row.municipality_code ?? '—' }) }}
          </li>
        </ul>
      </div>

      <template v-if="!loading && !loadError && preview && !result">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.workplace_bulk.summary.employments') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.employments }}</p>
          </article>
          <article class="rounded-lg bg-warning-50 p-3">
            <p class="text-xs text-warning-700">{{ t('payroll.workplace_bulk.summary.missing') }}</p>
            <p class="mt-1 text-lg font-semibold text-warning-700" data-test="workplace-bulk-missing">{{ preview.summary.missing }}</p>
          </article>
          <article class="rounded-lg bg-neutral-50 p-3">
            <p class="text-xs text-neutral-500">{{ t('payroll.workplace_bulk.summary.verified') }}</p>
            <p class="mt-1 text-lg font-semibold">{{ preview.summary.verified }}</p>
          </article>
          <article class="rounded-lg p-3" :class="preview.summary.excluded ? 'bg-danger-50' : 'bg-neutral-50'">
            <p class="text-xs" :class="preview.summary.excluded ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.workplace_bulk.summary.excluded') }}</p>
            <p class="mt-1 text-lg font-semibold" :class="preview.summary.excluded ? 'text-danger-700' : ''">{{ preview.summary.excluded }}</p>
          </article>
        </div>

        <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
          {{ t('payroll.workplace_bulk.notice') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.workplace_bulk.municipality') }}</span>
            <SearchableSelect
              :model-value="municipalityCode"
              :options="municipalityOptions.map(option => ({ value: option.code, label: option.label, secondary: option.code }))"
              :selected-option="selectedMunicipality"
              :remote="true"
              :disabled="applying"
              :loading="municipalitiesLoading"
              :placeholder="t('payroll.workplace_bulk.search_municipality')"
              accent="payroll"
              data-test="workplace-bulk-municipality"
              @search="searchMunicipalities"
              @update:model-value="selectMunicipality"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.workplace_bulk.country') }}</span>
            <select
              v-model="countryCode"
              :disabled="applying"
              data-test="workplace-bulk-country"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            >
              <option v-for="country in jmhzOptions?.countries ?? []" :key="country.code" :value="country.code">
                {{ country.code }} · {{ country.label }}
              </option>
            </select>
          </label>
        </div>

        <details
          v-if="preview.items.filter(item => item.state === 'excluded').length"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          data-test="workplace-bulk-excluded"
        >
          <summary class="cursor-pointer font-medium">
            {{ t('payroll.workplace_bulk.excluded_title', { count: preview.items.filter(item => item.state === 'excluded').length }) }}
          </summary>
          <ul class="mt-2 max-h-56 space-y-1 overflow-y-auto text-xs">
            <li v-for="item in preview.items.filter(candidate => candidate.state === 'excluded')" :key="item.employment_id">
              <span class="font-medium">{{ item.full_name }}:</span>
              {{ item.reason ? t(`payroll.workplace_bulk.exclusion_reason.${item.reason}`) : '' }}
            </li>
          </ul>
        </details>

        <p v-if="applyError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
          {{ applyError }}
        </p>

        <div class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="applying" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="cursor-pointer whitespace-nowrap"
              :class="btnFilled('primary')"
              :disabled="applying || applyCount === 0 || !municipalityCode"
              :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)"
              data-test="workplace-bulk-apply"
              @click="apply"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ applying ? t('common.saving') : t('payroll.workplace_bulk.apply', { count: applyCount }) }}
            </button>
            <p v-if="applyBlockedReason" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
          </div>
        </div>
      </template>

      <template v-if="result">
        <section data-test="workplace-bulk-result" class="space-y-3">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.workplace_bulk.result_title') }}</h3>
          <div class="grid grid-cols-3 gap-3">
            <article class="rounded-lg bg-success-50 p-3">
              <p class="text-xs text-success-700">{{ t('payroll.workplace_bulk.result.applied') }}</p>
              <p class="mt-1 text-lg font-semibold text-success-700" data-test="workplace-bulk-applied">{{ result.counts.applied }}</p>
            </article>
            <article class="rounded-lg p-3" :class="result.counts.skipped ? 'bg-warning-50' : 'bg-neutral-50'">
              <p class="text-xs" :class="result.counts.skipped ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll.workplace_bulk.result.skipped') }}</p>
              <p class="mt-1 text-lg font-semibold">{{ result.counts.skipped }}</p>
            </article>
            <article class="rounded-lg p-3" :class="result.counts.failed ? 'bg-danger-50' : 'bg-neutral-50'">
              <p class="text-xs" :class="result.counts.failed ? 'text-danger-700' : 'text-neutral-500'">{{ t('payroll.workplace_bulk.result.failed') }}</p>
              <p class="mt-1 text-lg font-semibold">{{ result.counts.failed }}</p>
            </article>
          </div>
          <div v-if="result.failed.length" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-xs text-danger-700" data-test="workplace-bulk-failed">
            <p class="font-medium">{{ t('payroll.workplace_bulk.failed_title', { count: result.failed.length }) }}</p>
            <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto">
              <li v-for="item in result.failed" :key="item.employment_id">
                <span class="font-medium">{{ itemLabel(item) }}:</span> {{ item.message }}
              </li>
            </ul>
          </div>
          <slot name="after-apply" :result="result" />
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" data-test="workplace-bulk-close" @click="emit('close')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.close') }}
            </button>
          </div>
        </section>
      </template>
    </div>
  </Modal>
</template>

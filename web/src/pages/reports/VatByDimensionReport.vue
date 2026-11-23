<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  reportsApi,
  type VatByDimensionAmountKey, type VatByDimensionAmounts, type VatByDimensionReport, type VatByDimensionRow,
} from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useVatReportPeriod } from '@/composables/useVatReportPeriod'
import { useDimensions } from '@/composables/useDimensions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'

/**
 * DPH podle dimenze: evidence DPH období (stejná data jako přiznání) rozpadnutá po
 * hodnotách jednoho typu dimenze. Doklad rozdělený mezi hodnoty se dělí po řádcích
 * a splitu. Kontrolní řádek porovná součet s evidencí, pod ním je most na přiznání
 * a kontrola obratů účtu 343 po hodnotách.
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const dims = useDimensions()

const { year, month, periodOverride, effectivePeriod: periodType, ready, settingsError, initialize } = useVatReportPeriod()

const report = ref<VatByDimensionReport | null>(null)
const loading = ref(true)
const error = ref('')

const types = computed(() => dims.types.value.filter(ty => ty.is_active))
const typeId = ref<number | null>(null)
const typesLoaded = ref(false)

// Typ dimenze drží URL (`type_id`), období řeší useVatReportPeriod.
watch(() => route.query.type_id, (value) => {
  const id = Number(value)
  if (Number.isSafeInteger(id) && id > 0) typeId.value = id
}, { immediate: true })

function setType(id: number | null) {
  typeId.value = id
  void router.replace({ query: { ...route.query, type_id: id ? String(id) : undefined } }).catch(() => {})
}

const AMOUNT_COLUMNS: { key: VatByDimensionAmountKey, labelKey: string }[] = [
  { key: 'vat_output', labelKey: 'reports.vat_by_dimension.col.vat_output' },
  { key: 'vat_input', labelKey: 'reports.vat_by_dimension.col.vat_input' },
  { key: 'vat_result', labelKey: 'reports.vat_by_dimension.col.vat_result' },
  { key: 'base_output_standard', labelKey: 'reports.vat_by_dimension.col.base_output_standard' },
  { key: 'base_output_reduced', labelKey: 'reports.vat_by_dimension.col.base_output_reduced' },
  { key: 'base_output_other', labelKey: 'reports.vat_by_dimension.col.base_output_other' },
  { key: 'base_input_standard', labelKey: 'reports.vat_by_dimension.col.base_input_standard' },
  { key: 'base_input_reduced', labelKey: 'reports.vat_by_dimension.col.base_input_reduced' },
  { key: 'base_input_other', labelKey: 'reports.vat_by_dimension.col.base_input_other' },
]
const VAT_COLUMNS = AMOUNT_COLUMNS.slice(0, 3)

let seq = 0
async function load() {
  if (!ready.value || !dims.enabled.value || !typeId.value) {
    if (ready.value && dims.enabled.value && !typeId.value) loading.value = false
    return
  }
  const id = ++seq
  loading.value = true
  error.value = ''
  report.value = null
  try {
    const result = await reportsApi.vatByDimensionPreview(year.value, month.value, periodType.value, typeId.value)
    if (id === seq) report.value = result
  } catch (e) {
    if (id === seq) error.value = apiErrorMessage(e)
  } finally {
    if (id === seq) loading.value = false
  }
}

function download(format: 'pdf' | 'xlsx') {
  if (!ready.value || !report.value || loading.value || !typeId.value) return
  window.open(reportsApi.vatByDimensionDownloadUrl(year.value, month.value, periodType.value, typeId.value, format), '_blank')
}

// Proklik na knihu DPH filtrovanou jen na tuto hodnotu (bez podřízených) a stejné období.
// remember=0: odkaz nemění zapamatované období ostatních výkazů DPH.
function bookLink(row: VatByDimensionRow) {
  const period = report.value?.period
  if (row.value_id === null || !period) return null
  return { path: '/reports/dph-book', query: {
    year: String(period.year), month: String(period.month), period: period.period_type,
    dimension_value_id: String(row.value_id), dimension_descendants: '0', remember: '0',
  } }
}

function rowLabel(row: VatByDimensionRow): string {
  return row.value_id === null ? t('reports.vat_by_dimension.no_dimension') : row.label
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
const yearOptions = useYearOptions('combined', year)
const quarterOptions = [1, 2, 3, 4]
const isQuarterly = computed(() => periodType.value === 'quarterly')
const currentQuarter = computed(() => Math.ceil(month.value / 3))
function setQuarter(q: number) {
  month.value = q * 3
}

function fmtMoney(v: number | null | undefined): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}
const isZero = (v: number | null | undefined) => Math.round(Math.abs(Number(v) || 0) * 100) === 0
const amountClass = (v: number) => isZero(v) ? '' : v < 0 ? 'text-danger-600' : ''
const differenceClass = (v: number) => isZero(v) ? 'text-success-700' : 'text-danger-600 font-semibold'
function amount(values: VatByDimensionAmounts, key: VatByDimensionAmountKey): number {
  return Number(values[key]) || 0
}

watch([ready, year, month, periodType, typeId], () => {
  if (!ready.value) {
    ++seq
    report.value = null
    loading.value = !settingsError.value
    return
  }
  void load()
})
watch(settingsError, value => {
  if (value) {
    error.value = apiErrorMessage(value)
    loading.value = false
  }
})
function retrySettings() {
  loading.value = true
  error.value = ''
  void initialize()
}

onMounted(async () => {
  void initialize()
  try { await dims.load() } catch { /* výběr typu zůstane prázdný */ }
  typesLoaded.value = true
  // Typ z URL platí, jen když ve firmě existuje; jinak první aktivní typ. Načtení
  // spustí watch na typ, případně na připravené období.
  if (!typeId.value || !types.value.some(ty => ty.id === typeId.value)) {
    const first = types.value[0]?.id ?? null
    if (first !== typeId.value) setType(first)
  }
  if (!dims.enabled.value || !typeId.value) loading.value = false
})

// V KeepAlive se po návratu načte znovu: doklady se mezitím mohly změnit v jiné záložce.
let activatedOnce = false
onActivated(() => {
  if (!activatedOnce) { activatedOnce = true; return }
  void load()
})
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.vat_by_dimension.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('reports.vat_by_dimension.subtitle') }}</p>
      </div>
      <div v-if="dims.enabled.value" class="flex items-center gap-2 flex-wrap max-md:min-w-0 max-md:max-w-full">
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" :disabled="!ready" @click="periodOverride = 'monthly'"
            :class="periodType === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer">
            {{ t('reports.dph.monthly') }}
          </button>
          <button type="button" :disabled="!ready" @click="periodOverride = 'quarterly'"
            :class="periodType === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer border-l border-neutral-300">
            {{ t('reports.dph.quarterly') }}
          </button>
        </div>
        <select :disabled="!ready" v-if="isQuarterly" :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-else :disabled="!ready" v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select :disabled="!ready" v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <label class="flex flex-wrap items-center gap-2 text-sm text-neutral-600 min-w-0 max-w-full">
          <span class="whitespace-nowrap">{{ t('reports.vat_by_dimension.type') }}</span>
          <select :value="typeId ?? ''" :aria-label="t('reports.vat_by_dimension.type')" data-test="vat-dimension-type"
            class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm min-w-0 max-w-full"
            @change="setType(Number(($event.target as HTMLSelectElement).value) || null)">
            <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </label>
        <template v-if="auth.canRead('reports.export')">
          <button type="button" @click="download('pdf')" :disabled="loading || !report" data-test="vat-dimension-pdf"
            :class="btnOutline('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('reports.vat_by_dimension.download_pdf') }}
          </button>
          <button type="button" @click="download('xlsx')" :disabled="loading || !report" data-test="vat-dimension-xlsx"
            :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
            {{ t('reports.vat_by_dimension.download_xlsx') }}
          </button>
        </template>
      </div>
    </div>

    <EmptyState v-if="!dims.enabled.value" boxed icon="tag" :title="t('dimensions.disabled_title')" :message="t('dimensions.disabled_hint')" />
    <EmptyState v-else-if="typesLoaded && types.length === 0" boxed icon="tag" :title="t('dimensions.types_empty')" to="/company/dimensions" :cta="t('dimensions.title')" />

    <div v-else-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm flex flex-wrap items-center gap-3">
      <span>{{ error }}</span>
      <button v-if="settingsError" type="button" @click="retrySettings" :class="btnOutline('neutral')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('common.retry') }}
      </button>
    </div>

    <div v-else-if="report" class="space-y-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
          {{ t('reports.dph_book.period_label') }}
        </div>
        <div class="text-lg font-semibold font-mono">{{ report.period.label }}</div>
        <div class="text-sm text-neutral-700 mt-1">
          {{ t('reports.vat_by_dimension.type') }}: <span class="font-medium" data-test="vat-dimension-type-name">{{ report.dimension_type.name }}</span>
        </div>
        <p class="text-xs text-neutral-500 mt-2">{{ t('reports.vat_by_dimension.split_note') }}</p>
        <ul v-if="report.warnings.length" class="mt-2 space-y-1 text-xs text-warning-700" data-test="vat-dimension-warnings">
          <li v-for="(warning, i) in report.warnings" :key="i">{{ warning }}</li>
        </ul>
      </div>

      <!-- Desktop: tabulka po hodnotách s kontrolními řádky -->
      <div class="max-md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-xs" data-test="vat-dimension-table">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ report.dimension_type.name }}</th>
              <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.documents') }}</th>
              <th v-for="col in AMOUNT_COLUMNS" :key="col.key" class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t(col.labelKey) }}</th>
              <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.book') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in report.rows" :key="row.value_id ?? 'none'" data-test="vat-dimension-row"
              :class="[row.value_id === null ? 'text-neutral-500 italic' : '', row.is_active ? '' : 'opacity-60']">
              <td class="px-3 py-1.5">
                <span class="block truncate max-w-md" :style="{ paddingLeft: `${row.depth * 1.25}rem` }" :title="rowLabel(row)">{{ rowLabel(row) }}</span>
              </td>
              <td class="px-2 py-1.5 text-right tabular-nums">{{ row.documents }}</td>
              <td v-for="col in AMOUNT_COLUMNS" :key="col.key" class="px-2 py-1.5 text-right font-mono whitespace-nowrap"
                :class="[amountClass(amount(row, col.key)), col.key === 'vat_result' ? 'font-semibold' : '']">
                {{ isZero(amount(row, col.key)) ? '–' : fmtMoney(amount(row, col.key)) }}
              </td>
              <td class="px-2 py-1.5 whitespace-nowrap">
                <RouterLink v-if="bookLink(row)" :to="bookLink(row)!" class="text-primary-700 hover:underline cursor-pointer"
                  :data-test="`vat-dimension-book-${row.value_id}`">{{ t('reports.vat_by_dimension.open_book') }}</RouterLink>
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-neutral-50">
            <tr class="font-semibold" data-test="vat-dimension-total">
              <td class="px-3 py-2">{{ t('reports.vat_by_dimension.total') }}</td>
              <td class="px-2 py-2" />
              <td v-for="col in AMOUNT_COLUMNS" :key="col.key" class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(amount(report.total, col.key)) }}</td>
              <td />
            </tr>
            <tr class="text-neutral-600" data-test="vat-dimension-evidence">
              <td class="px-3 py-2">{{ t('reports.vat_by_dimension.evidence') }}</td>
              <td class="px-2 py-2" />
              <td v-for="col in AMOUNT_COLUMNS" :key="col.key" class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(amount(report.control.evidence, col.key)) }}</td>
              <td />
            </tr>
            <tr data-test="vat-dimension-difference" :class="report.control.ok ? 'bg-success-50' : 'bg-danger-50'">
              <td class="px-3 py-2 font-semibold" :class="report.control.ok ? 'text-success-700' : 'text-danger-600'">{{ t('reports.vat_by_dimension.difference') }}</td>
              <td class="px-2 py-2" />
              <td v-for="col in AMOUNT_COLUMNS" :key="col.key" class="px-2 py-2 text-right font-mono whitespace-nowrap"
                :class="differenceClass(amount(report.control.difference, col.key))">{{ fmtMoney(amount(report.control.difference, col.key)) }}</td>
              <td />
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Telefon: karty po hodnotách -->
      <div class="md:hidden space-y-3" data-test="vat-dimension-cards">
        <div v-for="row in report.rows" :key="row.value_id ?? 'none'" class="bg-surface border border-neutral-200 rounded-lg p-4"
          :class="row.value_id === null ? 'text-neutral-500' : ''">
          <div class="flex items-start justify-between gap-3">
            <div class="font-medium min-w-0 break-words" :style="{ paddingLeft: `${row.depth * 0.75}rem` }">{{ rowLabel(row) }}</div>
            <div class="text-xs text-neutral-500 whitespace-nowrap">{{ t('reports.vat_by_dimension.documents_count', { n: row.documents }) }}</div>
          </div>
          <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
            <div v-for="col in VAT_COLUMNS" :key="col.key">
              <dt class="text-neutral-400">{{ t(col.labelKey) }}</dt>
              <dd class="font-mono" :class="amountClass(amount(row, col.key))">{{ fmtMoney(amount(row, col.key)) }}</dd>
            </div>
          </dl>
          <RouterLink v-if="bookLink(row)" :to="bookLink(row)!" class="mt-2 inline-block text-sm text-primary-700 hover:underline">
            {{ t('reports.vat_by_dimension.open_book') }}
          </RouterLink>
        </div>
        <div class="border rounded-lg p-4" :class="report.control.ok ? 'border-success-200 bg-success-50' : 'border-danger-200 bg-danger-50'">
          <div class="font-semibold text-sm">{{ t('reports.vat_by_dimension.total') }}</div>
          <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
            <div v-for="col in VAT_COLUMNS" :key="col.key">
              <dt class="text-neutral-500">{{ t(col.labelKey) }}</dt>
              <dd class="font-mono">{{ fmtMoney(amount(report.total, col.key)) }}</dd>
              <dd class="font-mono text-[11px]" :class="differenceClass(amount(report.control.difference, col.key))">
                {{ t('reports.vat_by_dimension.difference') }}: {{ fmtMoney(amount(report.control.difference, col.key)) }}
              </dd>
            </div>
          </dl>
        </div>
      </div>

      <div v-if="!report.control.ok && report.control.line_differences.length" class="bg-danger-50 border border-danger-500/40 rounded-lg p-4 text-sm" data-test="vat-dimension-line-differences">
        <p class="font-semibold text-danger-600">{{ t('reports.vat_by_dimension.line_differences') }}</p>
        <ul class="mt-2 space-y-1 text-xs text-neutral-700">
          <li v-for="diff in report.control.line_differences" :key="diff.line" class="font-mono">
            {{ t('reports.vat_by_dimension.line_difference', { line: diff.line, base: fmtMoney(diff.base), vat: fmtMoney(diff.vat) }) }}
          </li>
        </ul>
      </div>
      <p v-else-if="report.control.ok" class="text-xs text-success-700" data-test="vat-dimension-ok">{{ t('reports.vat_by_dimension.control_ok') }}</p>

      <!-- Most na přiznání: ř. 62/63/64 a opravy a zaokrouhlení přiznání -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" data-test="vat-dimension-return">
        <h2 class="font-semibold text-sm">{{ t('reports.vat_by_dimension.return_title') }}</h2>
        <p class="text-xs text-neutral-500 mt-1 max-w-3xl">{{ t('reports.vat_by_dimension.return_note') }}</p>
        <p v-if="report.control.return_error" class="mt-2 text-sm text-warning-700">{{ report.control.return_error }}</p>
        <div v-else-if="report.control.return" class="mt-3 overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="text-neutral-500">
              <tr>
                <th class="px-2 py-1.5 text-left font-medium" />
                <th v-for="col in VAT_COLUMNS" :key="col.key" class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t(col.labelKey) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr>
                <td class="px-2 py-1.5">{{ t('reports.vat_by_dimension.return_lines') }}</td>
                <td v-for="col in VAT_COLUMNS" :key="col.key" class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.control.return[col.key as 'vat_output' | 'vat_input' | 'vat_result']) }}</td>
              </tr>
              <tr class="text-neutral-600">
                <td class="px-2 py-1.5">{{ t('reports.vat_by_dimension.bridge') }}</td>
                <td v-for="col in VAT_COLUMNS" :key="col.key" class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.control.return.bridge[col.key as 'vat_output' | 'vat_input' | 'vat_result']) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Kontrola obratů účtu 343 po hodnotách -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4" data-test="vat-dimension-343">
        <h2 class="font-semibold text-sm">{{ t('reports.vat_by_dimension.account343_title') }}</h2>
        <p v-if="report.account343_unavailable" class="mt-2 text-sm text-neutral-600">{{ report.account343_unavailable }}</p>
        <template v-else-if="report.account343">
          <p class="text-xs text-neutral-500 mt-1 max-w-3xl">{{ report.account343.note }}</p>
          <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div class="overflow-x-auto">
              <table class="w-full text-xs">
                <thead class="bg-neutral-50 text-neutral-500">
                  <tr>
                    <th class="px-2 py-1.5 text-left font-medium">{{ report.dimension_type.name }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.debit') }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.credit') }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.net') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in report.account343.rows" :key="row.value_id ?? 'none'" data-test="vat-dimension-343-row"
                    :class="row.value_id === null ? 'text-neutral-500 italic' : ''">
                    <td class="px-2 py-1.5">{{ row.value_id === null ? t('reports.vat_by_dimension.no_dimension') : row.label }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.debit) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.credit) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold">{{ fmtMoney(row.net) }}</td>
                  </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-semibold">
                  <tr>
                    <td class="px-2 py-1.5">{{ t('reports.vat_by_dimension.total') }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.account343.total.debit) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.account343.total.credit) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.account343.total.net) }}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-xs" data-test="vat-dimension-343-comparison">
                <thead class="bg-neutral-50 text-neutral-500">
                  <tr>
                    <th class="px-2 py-1.5 text-left font-medium">{{ report.dimension_type.name }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.report_result') }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.col.account_net') }}</th>
                    <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('reports.vat_by_dimension.difference') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="(row, i) in report.account343.comparison" :key="row.is_total ? 'total' : (row.value_id ?? 'none') + ':' + i"
                    :class="row.is_total ? 'font-semibold border-t border-neutral-300' : ''">
                    <td class="px-2 py-1.5">{{ row.is_total ? t('reports.vat_by_dimension.total') : row.value_id === null ? t('reports.vat_by_dimension.no_dimension') : row.label }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.report_result) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.account_net) }}</td>
                    <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap" :class="differenceClass(row.difference)">{{ fmtMoney(row.difference) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <p class="mt-3 text-xs text-neutral-500" data-test="vat-dimension-343-excluded">
            {{ t('reports.vat_by_dimension.account343_excluded', {
              payments: report.account343.excluded.payments.count, payments_net: fmtMoney(report.account343.excluded.payments.net),
              opening: report.account343.excluded.opening.count, opening_net: fmtMoney(report.account343.excluded.opening.net),
            }) }}
          </p>
        </template>
      </div>
    </div>
  </div>
</template>

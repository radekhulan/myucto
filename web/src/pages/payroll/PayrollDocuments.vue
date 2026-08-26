<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollDocument,
  type PayrollDocumentBatchExit,
  type PayrollDocumentBatchReport,
  type PayrollDocumentList,
  type PayrollPeriodExportScope,
  type PayrollPersonOption,
  type PayrollTaxCertificateKind,
  type PayrollTaxCertificateGenerationPayload,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import { payrollQueryId, payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const period = ref(localPayrollPeriod())
const year = ref(Number(period.value.slice(0, 4)))
/**
 * Předvýběr z odkazu na kartě zaměstnance (`?person=7&tab=annual`).
 *
 * Roční tab je ten, na kterém se dokumenty za člověka VYSTAVUJÍ (mzdový list,
 * potvrzení o zdanitelných příjmech), takže na něj odkaz míří rovnou. Zúžení má
 * vlastní ref, ne `selectedEmployeeId`: ten říká „komu vystavit", ne „koho
 * vypsat", a přebít jeho význam by změnilo chování i bez odkazu.
 */
const requestedTab = payrollQueryValue(route.query, 'tab')
const activeTab = ref<'monthly' | 'annual' | 'archive'>(
  requestedTab === 'annual' || requestedTab === 'archive'
    ? requestedTab
    : 'monthly',
)
const focusPersonId = ref<number | null>(payrollQueryId(route.query, 'person'))
const data = ref<PayrollDocumentList | null>(null)
const annualItems = ref<PayrollDocument[]>([])
const people = ref<PayrollPersonOption[]>([])
const selectedEmployeeId = ref<number | null>(focusPersonId.value)
const loading = ref(true)
const generatingRevisionId = ref<number | null>(null)
const generatingBatchId = ref<number | null>(null)
const batchReport = ref<PayrollDocumentBatchReport | null>(null)
type AnnualGenerationKind = 'payroll_sheet' | PayrollTaxCertificateKind
const generatingAnnualKind = ref<AnnualGenerationKind | null>(null)
const pendingCorrectionKind = ref<PayrollTaxCertificateKind | null>(null)
const correctionReason = ref('')
const downloadingId = ref<number | null>(null)
const exportingScope = ref<PayrollPeriodExportScope | null>(null)
let loadSequence = 0

const COLUMNS: ColumnDef[] = [
  { key: 'document', labelKey: 'payroll.documents.document', required: true },
  { key: 'employee', labelKey: 'payroll.documents.employee' },
  { key: 'office', labelKey: 'payroll.documents.office' },
  { key: 'revision', labelKey: 'payroll.documents.revision' },
  { key: 'document_revision', labelKey: 'payroll.documents.document_revision', defaultHidden: true },
  { key: 'created', labelKey: 'payroll.documents.created' },
  { key: 'size', labelKey: 'payroll.documents.size' },
  { key: 'actions', labelKey: 'payroll.documents.actions', required: true },
]
const tbl = useTablePrefs('payroll-documents', COLUMNS)

// Měsíční i roční záložka sdílí jednu tabulku, takže si vystačí s jedním
// offsetem — zobrazená je vždy jen jedna z nich.
const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

function goToPage(nextPage: number): void {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

/** Změna filtru mění obsah seznamu, takže stránka musí zpět na začátek. */
function reload(): void {
  offset.value = 0
  void load()
}

const canGenerate = computed(() =>
  auth.canWrite('payroll.documents') && (data.value?.revisions.length ?? 0) > 0,
)
// Zúžení podle osoby zná SERVER (`employee_id` na obou výpisech) a padá do téhož
// dotazu jako stránkování. Dokud filtroval prohlížeč nad načtenou stránkou,
// dokument z druhé strany se tiše neprojevil a `total` v pageru mluvilo o celém
// období, ne o tom, co tabulka ukazuje.
const visibleItems = computed(() =>
  activeTab.value === 'monthly'
    ? data.value?.items ?? []
    : activeTab.value === 'annual'
      ? annualItems.value
      : [])
const focusName = computed(() => {
  const id = focusPersonId.value
  if (id === null) return null
  return people.value.find(person => person.id === id)?.full_name
    ?? t('payroll.agendas.focus.unknown_person')
})
/**
 * Server zúžení uplatnil a nezbylo nic. Tichá prázdná tabulka by tvrdila „ten
 * člověk tu nic nemá", i když je zúžení jen slepé (cizí osoba, zestaralý odkaz).
 */
const focusMissing = computed(() =>
  activeTab.value !== 'archive'
  && focusPersonId.value !== null
  && !loading.value
  && visibleItems.value.length === 0)
function clearFocus(): void {
  focusPersonId.value = null
  const query = { ...route.query }
  delete query.person
  void router.replace({ query })
  reload()
}
const employeeOptions = computed(() => people.value.map(person => ({
  value: person.id,
  label: person.full_name,
  secondary: person.needs_setup ? t('payroll.documents.employee_profile_incomplete') : undefined,
})))
const annualActions = computed<ActionItem[]>(() => {
  const disabled = selectedEmployeeId.value === null
    || generatingAnnualKind.value !== null
  return [
    {
      key: 'payroll-sheet',
      label: t('payroll.documents.generate_payroll_sheet'),
      icon: 'doc',
      tier: 'primary',
      variant: 'primary',
      disabled,
      loading: generatingAnnualKind.value === 'payroll_sheet',
      run: generatePayrollSheet,
    },
    {
      key: 'tax-certificate-advance',
      label: t('payroll.documents.generate_tax_certificate_advance'),
      icon: 'doc',
      tier: 'secondary',
      variant: 'primary',
      disabled,
      loading: generatingAnnualKind.value
        === 'taxable_income_advance_certificate',
      run: () => requestTaxCertificate(
        'taxable_income_advance_certificate',
      ),
    },
    {
      key: 'tax-certificate-withholding',
      label: t('payroll.documents.generate_tax_certificate_withholding'),
      icon: 'doc',
      tier: 'secondary',
      variant: 'primary',
      disabled,
      loading: generatingAnnualKind.value
        === 'taxable_income_withholding_certificate',
      run: () => requestTaxCertificate(
        'taxable_income_withholding_certificate',
      ),
    },
  ]
})

function kindLabel(item: PayrollDocument): string {
  return t(`payroll.documents.kind.${item.document_kind}`)
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(bytes / 1024)} kB`
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(bytes / (1024 * 1024))} MB`
}

function formatCreated(value: string): string {
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const date = new Date(normalized)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

function latestTaxCertificate(kind: PayrollTaxCertificateKind): PayrollDocument | null {
  const employeeId = selectedEmployeeId.value
  if (employeeId === null) return null

  return annualItems.value
    .filter(item => item.employee_id === employeeId && item.document_kind === kind)
    .reduce<PayrollDocument | null>((latest, item) => {
      if (latest === null) return item
      const itemRevision = item.document_revision_no ?? 0
      const latestRevision = latest.document_revision_no ?? 0
      if (itemRevision !== latestRevision) {
        return itemRevision > latestRevision ? item : latest
      }
      return item.id > latest.id ? item : latest
    }, null)
}

function cancelCorrection(): void {
  pendingCorrectionKind.value = null
  correctionReason.value = ''
}

function requestTaxCertificate(kind: PayrollTaxCertificateKind): void {
  const latest = latestTaxCertificate(kind)
  if (latest !== null) {
    pendingCorrectionKind.value = kind
    correctionReason.value = ''
    return
  }
  void generateTaxCertificate(kind, {
    supersedes_document_id: null,
    correction_reason: null,
  })
}

function submitCorrection(): void {
  const kind = pendingCorrectionKind.value
  const latest = kind === null ? null : latestTaxCertificate(kind)
  const reason = correctionReason.value.trim()
  if (kind === null || latest === null) return
  if (reason === '') {
    toast.error(t('payroll.documents.correction_reason_required'))
    return
  }
  void generateTaxCertificate(kind, {
    supersedes_document_id: latest.id,
    correction_reason: reason,
  })
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  const requestedYear = year.value
  const requestedTab = activeTab.value
  loading.value = true
  if (requestedTab === 'monthly') {
    data.value = null
  } else if (requestedTab === 'annual') {
    annualItems.value = []
  }
  try {
    const page = { limit: pageSize, offset: offset.value }
    if (requestedTab === 'monthly') {
      const loaded = await payrollApi.listDocuments(
        requestedPeriod,
        page,
        focusPersonId.value ?? undefined,
      )
      if (sequence === loadSequence && requestedPeriod === period.value) {
        data.value = loaded
        total.value = loaded.total
      }
      // Jmenný seznam se na měsíčním tabu jinak nenačítá, ale zúžení z odkazu
      // musí umět říct KOHO se týká — bez jména by byl filtr neviditelný.
      // Dotahuje se proto AŽ po výpisu a jen když zúžení opravdu je: jinak by
      // se výpis zdržoval o požadavek, který k ničemu není.
      if (focusPersonId.value !== null && people.value.length === 0) {
        people.value = await payrollApi.peopleOptions().catch(() => people.value)
      }
    } else if (requestedTab === 'annual') {
      const [loaded, loadedPeople] = await Promise.all([
        payrollApi.listAnnualDocuments(requestedYear, page, focusPersonId.value ?? undefined),
        people.value.length ? Promise.resolve(people.value) : payrollApi.peopleOptions(),
      ])
      if (sequence === loadSequence && requestedYear === year.value) {
        annualItems.value = loaded.items
        total.value = loaded.total
        people.value = loadedPeople
        if (selectedEmployeeId.value === null && loadedPeople.length === 1) {
          selectedEmployeeId.value = loadedPeople[0].id
        }
      }
    }
  } catch (error) {
    if (sequence === loadSequence) {
      toast.error(apiErrorMessage(error, t('payroll.documents.load_failed')))
    }
  } finally {
    if (sequence === loadSequence) {
      loading.value = false
    }
  }
}

async function downloadPeriodExport(scope: PayrollPeriodExportScope): Promise<void> {
  if (exportingScope.value !== null || !auth.canWrite('payroll.documents')) return
  exportingScope.value = scope
  try {
    const exported = await payrollApi.downloadPeriodExport(
      scope,
      scope === 'monthly' ? period.value : year.value,
    )
    toast.success(t('payroll.documents.period_export.downloaded', {
      filename: exported.suggested_filename,
    }))
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.documents.period_export.failed'),
    ))
  } finally {
    exportingScope.value = null
  }
}

async function generatePayrollSheet(): Promise<void> {
  const employeeId = selectedEmployeeId.value
  if (employeeId === null || generatingAnnualKind.value !== null) return
  generatingAnnualKind.value = 'payroll_sheet'
  try {
    await payrollApi.generatePayrollSheet(employeeId, year.value)
    toast.success(t('payroll.documents.payroll_sheet_created'))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.payroll_sheet_failed')))
  } finally {
    generatingAnnualKind.value = null
  }
}

async function generateTaxCertificate(
  kind: PayrollTaxCertificateKind,
  payload: PayrollTaxCertificateGenerationPayload,
): Promise<void> {
  const employeeId = selectedEmployeeId.value
  if (employeeId === null || generatingAnnualKind.value !== null) return
  generatingAnnualKind.value = kind
  try {
    await payrollApi.generateTaxCertificate(employeeId, year.value, kind, payload)
    toast.success(t('payroll.documents.tax_certificate_created'))
    cancelCorrection()
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.documents.tax_certificate_failed'),
    ))
  } finally {
    generatingAnnualKind.value = null
  }
}

async function generateBatch(revision: PayrollDocumentList['revisions'][number]): Promise<void> {
  if (generatingBatchId.value !== null) return
  generatingBatchId.value = revision.revision_id
  try {
    batchReport.value = await payrollApi.generateDocumentBatch(
      revision.run_id,
      revision.revision_id,
    )
    toast.success(t(
      batchReport.value.complete
        ? 'payroll.documents.batch_complete'
        : 'payroll.documents.batch_incomplete',
    ))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_failed')))
  } finally {
    generatingBatchId.value = null
  }
}

async function generateBundle(revision: PayrollDocumentList['revisions'][number]): Promise<void> {
  if (generatingRevisionId.value !== null) return
  generatingRevisionId.value = revision.revision_id
  try {
    await payrollApi.generateMonthlyBundle(
      revision.run_id,
      revision.revision_id,
      `payroll-monthly-bundle:${revision.run_id}:${revision.revision_id}`,
    )
    toast.success(t('payroll.documents.bundle_created'))
    await load()
  } catch {
    toast.error(t('payroll.documents.bundle_failed'))
  } finally {
    generatingRevisionId.value = null
  }
}

function batchExitLabel(exit: PayrollDocumentBatchExit): string {
  const certificate = exit.documents.employment_certificate
  if (certificate?.archived) return t('payroll.documents.batch_exit_archived')
  if (certificate?.available) return t('payroll.documents.batch_exit_pending')
  return t('payroll.documents.batch_exit_blocked', {
    code: certificate?.readiness_code ?? '',
  })
}

async function download(item: PayrollDocument): Promise<void> {
  if (downloadingId.value !== null) return
  downloadingId.value = item.id
  try {
    await payrollApi.downloadDocument(item)
  } catch {
    toast.error(t('payroll.documents.download_failed'))
  } finally {
    downloadingId.value = null
  }
}

watch(activeTab, () => {
  cancelCorrection()
  reload()
})
watch([selectedEmployeeId, year], cancelCorrection)
onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.documents.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.documents.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label v-if="activeTab === 'monthly'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.documents.period') }}</span>
          <input
            v-model="period"
            type="month"
            min="2024-01"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
            @change="reload"
          >
        </label>
        <label v-else-if="activeTab === 'annual'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.documents.year') }}</span>
          <input
            v-model.number="year"
            type="number"
            min="2000"
            max="2199"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
            @change="reload"
          >
        </label>
        <button
          v-if="activeTab !== 'archive'"
          type="button"
          :class="btnOutline('neutral')"
          :disabled="loading"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.documents.reload') }}
        </button>
        <template v-if="activeTab === 'monthly'">
          <button
            v-for="revision in canGenerate ? data?.revisions ?? [] : []"
            :key="revision.revision_id"
            type="button"
            data-test="generate-bundle"
            :class="btnFilled('primary')"
            :disabled="generatingRevisionId !== null"
            @click="generateBundle(revision)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.archive" />
            </svg>
            {{
              t(
                generatingRevisionId === revision.revision_id
                  ? 'payroll.documents.generating_bundle'
                  : 'payroll.documents.generate_bundle',
                { office: revision.office_name || t('payroll.documents.company') },
              )
            }}
          </button>
          <button
            v-for="revision in canGenerate ? data?.revisions ?? [] : []"
            :key="`batch-${revision.revision_id}`"
            type="button"
            data-test="generate-document-batch"
            :class="btnOutline('primary')"
            :disabled="generatingBatchId !== null"
            @click="generateBatch(revision)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.doc" />
            </svg>
            {{
              t(
                generatingBatchId === revision.revision_id
                  ? 'payroll.documents.batch_running'
                  : 'payroll.documents.batch_run',
                { office: revision.office_name || t('payroll.documents.company') },
              )
            }}
          </button>
        </template>
      </div>
    </header>

    <section
      v-if="batchReport"
      class="rounded-lg border p-4"
      :class="batchReport.complete
        ? 'border-success-500/30 bg-success-50'
        : 'border-warning-500/30 bg-warning-50'"
      data-test="document-batch-report"
      role="status"
    >
      <h2 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.documents.batch_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-700">
        {{ t('payroll.documents.batch_summary', {
          payslips: batchReport.payslips.archived,
          from: batchReport.period_start,
          to: batchReport.period_end,
        }) }}
      </p>
      <p class="mt-1 text-sm font-medium text-neutral-900">
        {{ t(batchReport.complete
          ? 'payroll.documents.batch_complete'
          : 'payroll.documents.batch_incomplete') }}
      </p>
      <ul v-if="batchReport.employment_exits.length" class="mt-3 space-y-1 text-sm text-neutral-700">
        <li v-for="exit in batchReport.employment_exits" :key="exit.employment_id">
          {{ exit.employee_name || exit.employment_id }} · {{ exit.end_date }} · {{ batchExitLabel(exit) }}
        </li>
      </ul>
      <p v-else class="mt-3 text-sm text-neutral-600">
        {{ t('payroll.documents.batch_no_exits') }}
      </p>
    </section>

    <PayrollFocusNotice
      v-if="activeTab !== 'archive' && focusMissing"
      :name="String(focusPersonId)"
      missing
      @clear="clearFocus"
    />
    <PayrollFocusNotice
      v-else-if="activeTab !== 'archive' && focusName"
      :name="focusName"
      @clear="clearFocus"
    />

    <nav class="flex gap-1 overflow-x-auto border-b border-neutral-200" :aria-label="t('payroll.documents.tabs_label')">
      <button
        v-for="tab in (['monthly', 'annual', 'archive'] as const)"
        :key="tab"
        type="button"
        :class="[
          'whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors',
          activeTab === tab
            ? 'border-payroll-500 text-payroll-600'
            : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900',
        ]"
        @click="activeTab = tab"
      >
        {{ t(`payroll.documents.tabs.${tab}`) }}
      </button>
    </nav>

    <section
      v-if="activeTab === 'archive'"
      data-test="period-export-panel"
      class="rounded-xl border border-payroll-500/20 bg-surface p-5 shadow-sm"
    >
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.archive" />
        </svg>
        <div class="max-w-4xl">
          <h2 class="font-semibold text-neutral-900">
            {{ t('payroll.documents.period_export.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.documents.period_export.description') }}
          </p>
          <p class="mt-2 text-xs text-neutral-500">
            {{ t('payroll.documents.period_export.security_hint') }}
          </p>
        </div>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <article class="rounded-lg border border-neutral-200 bg-neutral-50 p-4">
          <h3 class="font-medium text-neutral-900">
            {{ t('payroll.documents.period_export.monthly_title') }}
          </h3>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.documents.period_export.monthly_hint') }}
          </p>
          <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="block min-w-44 flex-1">
              <span class="form-label">{{ t('payroll.documents.period') }}</span>
              <input
                v-model="period"
                data-test="period-export-month"
                type="month"
                min="2024-01"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
              >
            </label>
            <button
              type="button"
              data-test="download-monthly-period-export"
              :class="btnFilled('primary')"
              :disabled="exportingScope !== null || !auth.canWrite('payroll.documents')"
              @click="downloadPeriodExport('monthly')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.download" />
              </svg>
              {{ t(exportingScope === 'monthly'
                ? 'payroll.documents.period_export.preparing'
                : 'payroll.documents.period_export.download_monthly') }}
            </button>
          </div>
        </article>

        <article class="rounded-lg border border-neutral-200 bg-neutral-50 p-4">
          <h3 class="font-medium text-neutral-900">
            {{ t('payroll.documents.period_export.annual_title') }}
          </h3>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.documents.period_export.annual_hint') }}
          </p>
          <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="block min-w-44 flex-1">
              <span class="form-label">{{ t('payroll.documents.year') }}</span>
              <input
                v-model.number="year"
                data-test="period-export-year"
                type="number"
                min="2000"
                max="2199"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
              >
            </label>
            <button
              type="button"
              data-test="download-annual-period-export"
              :class="btnFilled('primary')"
              :disabled="exportingScope !== null || !auth.canWrite('payroll.documents')"
              @click="downloadPeriodExport('annual')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.download" />
              </svg>
              {{ t(exportingScope === 'annual'
                ? 'payroll.documents.period_export.preparing'
                : 'payroll.documents.period_export.download_annual') }}
            </button>
          </div>
        </article>
      </div>

      <p
        v-if="!auth.canWrite('payroll.documents')"
        class="mt-4 text-sm text-warning-700"
      >
        {{ t('payroll.documents.period_export.permission_required') }}
      </p>
    </section>

    <section
      v-if="activeTab === 'annual' && auth.canWrite('payroll.documents')"
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
    >
      <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-64 flex-1">
          <span class="form-label">{{ t('payroll.documents.select_employee') }}</span>
          <SearchableSelect
            v-model="selectedEmployeeId"
            :options="employeeOptions"
            :placeholder="t('payroll.documents.select_employee_placeholder')"
            :clearable="false"
            accent="payroll"
            :aria-label="t('payroll.documents.select_employee')"
          />
        </label>
        <ActionBar :actions="annualActions" />
      </div>
      <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.documents.payroll_sheet_hint') }}</p>
      <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.documents.tax_certificate_hint') }}</p>
      <form
        v-if="pendingCorrectionKind && latestTaxCertificate(pendingCorrectionKind)"
        data-test="tax-certificate-correction"
        class="mt-4 rounded-lg border border-warning-500/40 bg-warning-50 p-4"
        @submit.prevent="submitCorrection"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">{{ t('payroll.documents.correction_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600">
              {{
                t('payroll.documents.correction_hint', {
                  document: latestTaxCertificate(pendingCorrectionKind)?.id,
                  created: formatCreated(latestTaxCertificate(pendingCorrectionKind)?.created_at ?? ''),
                })
              }}
            </p>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="cancelCorrection">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('payroll.documents.correction_cancel') }}
          </button>
        </div>
        <label class="mt-4 block">
          <span class="form-label">{{ t('payroll.documents.correction_reason') }}</span>
          <textarea
            v-model="correctionReason"
            data-test="correction-reason"
            required
            rows="3"
            maxlength="1000"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
            :placeholder="t('payroll.documents.correction_reason_placeholder')"
          />
        </label>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button
            type="submit"
            data-test="submit-tax-certificate-correction"
            :class="btnFilled('warning')"
            :disabled="generatingAnnualKind !== null"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.doc" />
            </svg>
            {{ t('payroll.documents.correction_submit') }}
          </button>
        </div>
      </form>
    </section>

    <section class="rounded-xl border border-payroll-500/20 bg-payroll-50 p-4 text-sm text-neutral-700">
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path :d="ICONS.checkCircle" />
        </svg>
        <div>
          <p>{{ t('payroll.documents.integrity_hint') }}</p>
          <p v-if="activeTab === 'monthly' && data?.revisions.length" class="mt-1 text-xs text-neutral-600">
            {{ t('payroll.documents.approved_revisions', { count: data.revisions.length }) }}
          </p>
          <p v-else-if="activeTab === 'monthly' && !loading" class="mt-1 text-xs text-warning-700">
            {{ t('payroll.documents.revision_unavailable') }}
          </p>
        </div>
      </div>
    </section>

    <div v-if="activeTab !== 'archive' && loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <section
      v-else-if="activeTab !== 'archive' && !visibleItems.length"
      class="rounded-xl border border-dashed border-neutral-300 bg-surface px-5 py-12 text-center"
    >
      <svg class="mx-auto h-10 w-10 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path :d="ICONS.doc" />
      </svg>
      <h2 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.documents.empty_title') }}</h2>
      <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-500">{{ t('payroll.documents.empty_description') }}</p>
    </section>

    <template v-else-if="activeTab !== 'archive'">
      <section data-test="documents-table" class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
        <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th v-if="tbl.isVisible('document')" class="px-4 py-3">{{ t('payroll.documents.document') }}</th>
                <th v-if="tbl.isVisible('employee')" class="px-4 py-3">{{ t('payroll.documents.employee') }}</th>
                <th v-if="tbl.isVisible('office')" class="px-4 py-3">{{ t('payroll.documents.office') }}</th>
                <th v-if="tbl.isVisible('revision')" class="px-4 py-3">{{ t('payroll.documents.revision') }}</th>
                <th v-if="tbl.isVisible('document_revision')" class="px-4 py-3">{{ t('payroll.documents.document_revision') }}</th>
                <th v-if="tbl.isVisible('created')" class="px-4 py-3">{{ t('payroll.documents.created') }}</th>
                <th v-if="tbl.isVisible('size')" class="px-4 py-3 text-right">{{ t('payroll.documents.size') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('payroll.documents.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in visibleItems" :key="item.id">
                <td v-if="tbl.isVisible('document')" class="px-4 py-3 font-medium text-neutral-900">{{ kindLabel(item) }}</td>
                <td v-if="tbl.isVisible('employee')" class="px-4 py-3 text-neutral-600">{{ item.employee_name || t('payroll.documents.company') }}</td>
                <td v-if="tbl.isVisible('office')" class="px-4 py-3 text-neutral-600">{{ item.office_name || (item.tax_year ? String(item.tax_year) : t('payroll.documents.company')) }}</td>
                <td v-if="tbl.isVisible('revision')" class="px-4 py-3 text-neutral-600">{{ item.annual_revision_no ?? item.revision_no ?? '—' }}</td>
                <td v-if="tbl.isVisible('document_revision')" class="px-4 py-3 text-neutral-600">{{ item.document_revision_no ?? '—' }}</td>
                <td v-if="tbl.isVisible('created')" class="whitespace-nowrap px-4 py-3 text-neutral-600">{{ formatCreated(item.created_at) }}</td>
                <td v-if="tbl.isVisible('size')" class="whitespace-nowrap px-4 py-3 text-right text-neutral-600">{{ formatSize(item.size_bytes) }}</td>
                <td v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">
                  <button
                    type="button"
                    data-test="download-document"
                    :class="btnOutline('neutral')"
                    :disabled="downloadingId !== null"
                    @click="download(item)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path :d="ICONS.download" />
                    </svg>
                    {{ t('payroll.documents.download') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section data-test="documents-cards" class="grid grid-cols-1 gap-3 md:hidden">
        <article v-for="item in visibleItems" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="font-semibold text-neutral-900">{{ kindLabel(item) }}</h2>
              <p class="mt-1 truncate text-sm text-neutral-600">{{ item.employee_name || t('payroll.documents.company') }}</p>
            </div>
            <span class="shrink-0 rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-600">
              {{ formatSize(item.size_bytes) }}
            </span>
          </div>
          <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
            <div>
              <dt class="text-neutral-500">{{ t('payroll.documents.revision') }}</dt>
              <dd class="mt-0.5 text-neutral-800">{{ item.annual_revision_no ?? item.revision_no ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('payroll.documents.office') }}</dt>
              <dd class="mt-0.5 text-neutral-800">{{ item.office_name || (item.tax_year ? String(item.tax_year) : t('payroll.documents.company')) }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('payroll.documents.document_revision') }}</dt>
              <dd class="mt-0.5 text-neutral-800">{{ item.document_revision_no ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('payroll.documents.created') }}</dt>
              <dd class="mt-0.5 text-neutral-800">{{ formatCreated(item.created_at) }}</dd>
            </div>
          </dl>
          <button
            type="button"
            data-test="download-document"
            :class="[btnOutline('neutral'), 'mt-4 w-full justify-center']"
            :disabled="downloadingId !== null"
            @click="download(item)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.download" />
            </svg>
            {{ t('payroll.documents.download') }}
          </button>
        </article>
      </section>

      <PaginationBar
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </template>
  </div>
</template>

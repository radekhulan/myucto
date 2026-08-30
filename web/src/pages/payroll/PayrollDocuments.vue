<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollAnnualDocumentBatch,
  type PayrollAnnualDocumentBatchItem,
  type PayrollDocument,
  type PayrollDocumentBatch,
  type PayrollDocumentBatchItem,
  type PayrollDocumentList,
  type PayrollDocumentSecureLink,
  type PayrollPeriodExportScope,
  type PayrollSecureDeliveryBlockedReason,
  type PayrollTaxCertificateKind,
  type PayrollTaxCertificateGenerationPayload,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
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
const focusPersonName = ref<string | null>(null)
const selectedEmployeeId = ref<number | null>(focusPersonId.value)
const loading = ref(true)
const generatingBatchId = ref<number | null>(null)
const documentBatch = ref<PayrollDocumentBatch | null>(null)
const documentBatchItems = ref<PayrollDocumentBatchItem[]>([])
const retryingBatchItemId = ref<number | null>(null)
type AnnualGenerationKind = 'payroll_sheet' | PayrollTaxCertificateKind
const generatingAnnualKind = ref<AnnualGenerationKind | null>(null)
const pendingCorrectionKind = ref<PayrollTaxCertificateKind | null>(null)
const correctionReason = ref('')
const downloadingId = ref<number | null>(null)
const downloadingBundle = ref(false)
const bundleError = ref('')
const exportingScope = ref<PayrollPeriodExportScope | null>(null)
let loadSequence = 0
let batchPollTimer: ReturnType<typeof setTimeout> | null = null
let annualBatchPollTimer: ReturnType<typeof setTimeout> | null = null

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
  if (focusPersonId.value === null) return null
  return focusPersonName.value ?? t('payroll.agendas.focus.unknown_person')
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
  focusPersonName.value = null
  const query = { ...route.query }
  delete query.person
  void router.replace({ query })
  reload()
}

async function loadFocusPersonName(): Promise<void> {
  const employeeId = focusPersonId.value
  if (employeeId === null || focusPersonName.value !== null) return
  try {
    const person = await payrollApi.person(employeeId)
    if (focusPersonId.value === employeeId) focusPersonName.value = person.full_name
  } catch {
    // Neplatný nebo již nepřístupný deep-link přizná stávající text „neznámá osoba".
  }
}
/*
 * ─── Roční dokumenty za VÍC lidí ────────────────────────────────────────────
 *
 * Do W29 šly roční dokumenty jen po jednom, pak je nahradila smyčka
 * v prohlížeči: jeden požadavek na zaměstnance, synchronně. U firmy s pěti sty
 * lidmi to bylo pět set požadavků, které skončily na timeoutu nebo zavřením
 * záložky — a rozdělaná dávka se ztratila i s tím, co už bylo hotové.
 *
 * Teď jde ven JEDEN požadavek, který dávku zařadí do serverové fronty
 * (`payroll_annual_document_batches`), a prohlížeč jen sleduje průběh. Běh
 * přežije zavřený prohlížeč i restart, položky mají vlastní pokusy s odkladem
 * a neúspěšný řádek jde opakovat jednotlivě.
 *
 * Osoby, které už potvrzení daného druhu za rok mají, se PŘESKAKUJÍ (`skipped`):
 * jejich nahrazení je oprava (§ opravné potvrzení) a ta má povinný důvod, který
 * za uživatele vymyslet nelze. O tom teď rozhoduje SERVER nad úplnými daty, ne
 * prohlížeč nad načtenou stránkou. Vypíšou se jménem, aby bylo jasné, že
 * nezmizely.
 */
const batchScope = ref<'selected' | 'all'>('selected')
const enqueuingAnnualKind = ref<AnnualGenerationKind | null>(null)
const annualBatch = ref<PayrollAnnualDocumentBatch | null>(null)
const annualBatchItems = ref<PayrollAnnualDocumentBatchItem[]>([])
const retryingAnnualItemId = ref<number | null>(null)

const annualBatchKind = computed<AnnualGenerationKind | null>(() =>
  annualBatch.value?.document_kind ?? null)
const annualBatchOpen = computed(() =>
  annualBatch.value !== null && annualBatch.value.status !== 'completed')
/** Tlačítka blokuje jen NEDOKONČENÁ dávka; hotová zpráva zůstává vidět. */
const batchActive = computed(() =>
  enqueuingAnnualKind.value !== null || annualBatchOpen.value)
const batchRunningKind = computed<AnnualGenerationKind | null>(() =>
  enqueuingAnnualKind.value ?? (annualBatchOpen.value ? annualBatchKind.value : null))
const batchTotal = computed(() => annualBatch.value?.item_count ?? 0)
const batchDone = computed(() => {
  const batch = annualBatch.value
  if (batch === null) return 0
  return batch.succeeded_count + batch.failed_count + batch.skipped_count
})
const batchSkipped = computed(() =>
  annualBatchItems.value
    .filter(item => item.status === 'skipped')
    .map(item => ({ id: item.id, name: item.employee_name })))
/**
 * Chyba se hlásí za KAŽDÉHO ČLOVĚKA jménem i důvodem, ne jako počet — to byla
 * jediná věc, kterou klientská smyčka uměla dobře, a nesmí se ztratit.
 */
const batchFailures = computed(() =>
  annualBatchItems.value
    .filter(item => item.status === 'failed' || item.status === 'retry_wait')
    .map(item => ({
      id: item.id,
      name: item.employee_name,
      reason: item.last_error_message
        ?? t('payroll.documents.batch_annual.item_failed'),
      retriable: true,
    })))

function clearAnnualBatchPoll(): void {
  if (annualBatchPollTimer !== null) clearTimeout(annualBatchPollTimer)
  annualBatchPollTimer = null
}

/** Zavře jen zprávu v prohlížeči. Dávka na serveru běží dál. */
function dismissAnnualBatch(): void {
  clearAnnualBatchPoll()
  annualBatch.value = null
  annualBatchItems.value = []
}

async function loadAnnualBatchItems(batchId: number): Promise<void> {
  const items: PayrollAnnualDocumentBatchItem[] = []
  let nextOffset = 0
  let total = 1
  while (nextOffset < total) {
    const page = await payrollApi.annualDocumentBatchItems(batchId, {
      limit: 100,
      offset: nextOffset,
    })
    items.push(...page.items)
    total = page.total
    nextOffset += page.items.length
    if (page.items.length === 0) break
  }
  if (annualBatch.value?.id === batchId) annualBatchItems.value = items
}

async function pollAnnualBatch(loadItems = false): Promise<void> {
  const batchId = annualBatch.value?.id
  if (!batchId) return
  clearAnnualBatchPoll()
  try {
    const previous = annualBatch.value
    const current = await payrollApi.annualDocumentBatch(batchId)
    if (annualBatch.value?.id !== batchId) return
    annualBatch.value = current
    const changed = previous === null
      || previous.status !== current.status
      || previous.succeeded_count !== current.succeeded_count
      || previous.failed_count !== current.failed_count
      || previous.skipped_count !== current.skipped_count
    if (loadItems || changed) await loadAnnualBatchItems(batchId)
    if (current.status === 'completed') {
      await load()
      return
    }
    annualBatchPollTimer = setTimeout(() => void pollAnnualBatch(), 2500)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_annual.poll_failed')))
  }
}

async function runAnnualBatch(kind: AnnualGenerationKind): Promise<void> {
  if (batchActive.value || generatingAnnualKind.value !== null) return
  clearAnnualBatchPoll()
  annualBatchItems.value = []
  enqueuingAnnualKind.value = kind
  try {
    annualBatch.value = await payrollApi.enqueueAnnualDocumentBatch(
      kind,
      year.value,
      batchScope.value,
      batchScope.value === 'selected' ? selectedEmployeeId.value : null,
    )
    toast.success(t('payroll.documents.batch_annual.queued', {
      count: annualBatch.value.item_count,
    }))
    await pollAnnualBatch(true)
  } catch (error) {
    annualBatch.value = null
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_annual.enqueue_failed')))
  } finally {
    enqueuingAnnualKind.value = null
  }
}

async function retryAnnualBatchItem(itemId: number): Promise<void> {
  const batchId = annualBatch.value?.id
  if (!batchId || retryingAnnualItemId.value !== null) return
  retryingAnnualItemId.value = itemId
  try {
    await payrollApi.retryAnnualDocumentBatchItem(batchId, itemId)
    toast.success(t('payroll.documents.batch_annual.retry_queued'))
    await pollAnnualBatch(true)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_annual.retry_failed')))
  } finally {
    retryingAnnualItemId.value = null
  }
}

/**
 * Jedna osoba jde pořád starou cestou — kvůli OPRAVĚ.
 *
 * U jednotlivce se existující potvrzení nahrazuje opravným dokladem s povinným
 * důvodem (`requestTaxCertificate` na to otevře formulář). V dávce se takový
 * člověk PŘESKOČÍ a vypíše: důvod opravy za uživatele vymyslet nelze a padesát
 * formulářů po sobě není hromadná akce.
 */
function runAnnualKind(kind: AnnualGenerationKind): void {
  if (batchScope.value === 'all') {
    void runAnnualBatch(kind)
    return
  }
  if (kind === 'payroll_sheet') {
    void generatePayrollSheet()
    return
  }
  requestTaxCertificate(kind)
}

const annualBlockedReason = computed<string | null>(() => {
  if (batchScope.value === 'selected' && selectedEmployeeId.value === null) {
    return t('payroll.documents.batch_annual.blocked_no_person')
  }
  return null
})

const annualActions = computed<ActionItem[]>(() => {
  const disabled = annualBlockedReason.value !== null
    || batchActive.value
    || generatingAnnualKind.value !== null
  return [
    {
      key: 'payroll-sheet',
      label: t('payroll.documents.generate_payroll_sheet'),
      icon: 'doc',
      tier: 'primary',
      variant: 'primary',
      disabled,
      disabledReason: annualBlockedReason.value ?? undefined,
      loading: generatingAnnualKind.value === 'payroll_sheet'
        || batchRunningKind.value === 'payroll_sheet',
      run: () => runAnnualKind('payroll_sheet'),
    },
    {
      key: 'tax-certificate-advance',
      label: t('payroll.documents.generate_tax_certificate_advance'),
      icon: 'doc',
      tier: 'secondary',
      variant: 'primary',
      disabled,
      disabledReason: annualBlockedReason.value ?? undefined,
      loading: generatingAnnualKind.value === 'taxable_income_advance_certificate'
        || batchRunningKind.value === 'taxable_income_advance_certificate',
      run: () => runAnnualKind('taxable_income_advance_certificate'),
    },
    {
      key: 'tax-certificate-withholding',
      label: t('payroll.documents.generate_tax_certificate_withholding'),
      icon: 'doc',
      tier: 'secondary',
      variant: 'primary',
      disabled,
      disabledReason: annualBlockedReason.value ?? undefined,
      loading: generatingAnnualKind.value === 'taxable_income_withholding_certificate'
        || batchRunningKind.value === 'taxable_income_withholding_certificate',
      run: () => runAnnualKind('taxable_income_withholding_certificate'),
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
      const [loaded] = await Promise.all([
        payrollApi.listDocuments(
          requestedPeriod,
          page,
          focusPersonId.value ?? undefined,
        ),
        loadFocusPersonName(),
      ])
      if (sequence === loadSequence && requestedPeriod === period.value) {
        data.value = loaded
        total.value = loaded.total
        void loadSecureLinksFor(loaded.items)
      }
    } else if (requestedTab === 'annual') {
      const [loaded] = await Promise.all([
        payrollApi.listAnnualDocuments(requestedYear, page, focusPersonId.value ?? undefined),
        loadFocusPersonName(),
      ])
      if (sequence === loadSequence && requestedYear === year.value) {
        annualItems.value = loaded.items
        total.value = loaded.total
        void loadSecureLinksFor(loaded.items)
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
    documentBatch.value = await payrollApi.generateDocumentBatch(
      revision.run_id,
      revision.revision_id,
    )
    toast.success(t('payroll.documents.batch_queued'))
    await loadDocumentBatch(true)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_failed')))
  } finally {
    generatingBatchId.value = null
  }
}

function clearBatchPoll(): void {
  if (batchPollTimer !== null) clearTimeout(batchPollTimer)
  batchPollTimer = null
}

async function loadAllBatchItems(batchId: number): Promise<void> {
  const items: PayrollDocumentBatchItem[] = []
  let nextOffset = 0
  let total = 1
  while (nextOffset < total) {
    const page = await payrollApi.documentBatchItems(batchId, {
      limit: 100,
      offset: nextOffset,
    })
    items.push(...page.items)
    total = page.total
    nextOffset += page.items.length
    if (page.items.length === 0) break
  }
  if (documentBatch.value?.id === batchId) documentBatchItems.value = items
}

async function loadDocumentBatch(loadItems = false): Promise<void> {
  const batchId = documentBatch.value?.id
  if (!batchId) return
  clearBatchPoll()
  try {
    const previous = documentBatch.value
    const current = await payrollApi.documentBatch(batchId)
    if (documentBatch.value?.id !== batchId) return
    documentBatch.value = current
    const changed = previous === null
      || previous.status !== current.status
      || previous.succeeded_count !== current.succeeded_count
      || previous.failed_count !== current.failed_count
    if (loadItems || changed) await loadAllBatchItems(batchId)
    if (current.status === 'completed') {
      toast.success(t('payroll.documents.batch_complete'))
      await load()
      return
    }
    if (current.status === 'failed') return
    batchPollTimer = setTimeout(() => void loadDocumentBatch(), 2500)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_poll_failed')))
  }
}

async function retryBatchItem(item: PayrollDocumentBatchItem): Promise<void> {
  const batchId = documentBatch.value?.id
  if (!batchId || retryingBatchItemId.value !== null) return
  retryingBatchItemId.value = item.id
  try {
    await payrollApi.retryDocumentBatchItem(batchId, item.id)
    toast.success(t('payroll.documents.batch_retry_queued'))
    await loadDocumentBatch(true)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.batch_retry_failed')))
  } finally {
    retryingBatchItemId.value = null
  }
}

/**
 * Hotový balík se STAHUJE, neoznamuje.
 *
 * Dřív tu byla jen věta „balík je připraven" a jediná cesta k němu vedla přes
 * archiv a export období — z panelu, který o balíku ví, nevedl žádný odkaz.
 * Chyba se hlásí větou v panelu, ne mizícím toastem: uživatel na to tlačítko
 * kliknul a musí se dozvědět, proč nemá soubor.
 */
async function downloadBundle(): Promise<void> {
  const documentId = documentBatch.value?.bundle_document_id
  if (documentId === null || documentId === undefined || downloadingBundle.value) return
  downloadingBundle.value = true
  bundleError.value = ''
  try {
    await payrollApi.downloadDocumentById(
      documentId,
      documentBatch.value?.bundle_filename
        || `${t('payroll.documents.batch_bundle_ready')}.zip`,
    )
  } catch (error) {
    bundleError.value = apiErrorMessage(
      error,
      t('payroll.documents.batch_bundle_failed'),
    )
  } finally {
    downloadingBundle.value = false
  }
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

/*
 * ─── Zabezpečené doručení osobního dokumentu ───────────────────────────────
 *
 * Odkaz i token zná jen zaměstnanec ve své schránce — API je záměrně nevrací
 * (viz `PayrollDocumentDeliveryAction`), takže tahle stránka o nich nikdy nic
 * nezobrazí. Ví jen o STAVU: komu (maskovaně) odkaz šel, jestli je živý a jestli
 * si dokument zaměstnanec sám vyzvedl.
 *
 * Odkazy se dotahují jen pro řádky, které je NĚKDY dostaly (`delivery.secure_link_sent_at`)
 * — u zbytku by dotaz byl zbytečný a stránka by při každém načtení stránkovala
 * o dávku požadavků navíc.
 */
const secureLinksByDocument = ref<Map<number, PayrollDocumentSecureLink[]>>(new Map())
const sendingSecureLinkDocumentId = ref<number | null>(null)
const revokingSecureLinkId = ref<number | null>(null)

function liveSecureLink(documentId: number): PayrollDocumentSecureLink | null {
  const links = secureLinksByDocument.value.get(documentId)
  return links?.find(link => link.is_live) ?? null
}

async function loadSecureLinksFor(items: PayrollDocument[]): Promise<void> {
  const candidates = items.filter(item => item.employee_id !== null && item.delivery?.secure_link_sent_at)
  await Promise.all(candidates.map(async (item) => {
    try {
      const links = await payrollApi.documentSecureLinks(item.id)
      secureLinksByDocument.value.set(item.id, links)
    } catch {
      // Tiché selhání: řádek jen dočasně nenabídne zneplatnění odkazu.
    }
  }))
}

const SECURE_DELIVERY_REASON_KEYS: Record<string, string> = {
  secure_delivery_disabled: 'payroll.documents.secure_delivery.reason.secure_delivery_disabled',
  employer_channel_not_portal: 'payroll.documents.secure_delivery.reason.employer_channel_not_portal',
  employer_channel_unverified: 'payroll.documents.secure_delivery.reason.employer_channel_unverified',
  employee_prefers_paper: 'payroll.documents.secure_delivery.reason.employee_prefers_paper',
  recipient_email_missing: 'payroll.documents.secure_delivery.reason.recipient_email_missing',
  recipient_email_ambiguous: 'payroll.documents.secure_delivery.reason.recipient_email_ambiguous',
  document_not_personal: 'payroll.documents.secure_delivery.reason.document_not_personal',
}
/** Věta, který z přepínačů odeslání blokuje. `null` u neznámého/chybějícího důvodu. */
function secureDeliveryReasonMessage(reason: PayrollSecureDeliveryBlockedReason | undefined): string | null {
  if (!reason) return null
  const key = SECURE_DELIVERY_REASON_KEYS[reason]
  return key ? t(key) : null
}

/** Kompaktní stav řádku: "Neodesláno" / "Odesláno <datum>" / "Převzato <datum>". */
function secureDeliveryStatusText(item: PayrollDocument): string {
  const delivery = item.delivery
  if (delivery?.self_downloaded_at) {
    return t('payroll.documents.secure_delivery.status.picked_up', { date: formatCreated(delivery.self_downloaded_at) })
  }
  if (delivery?.secure_link_sent_at) {
    return t('payroll.documents.secure_delivery.status.sent', { date: formatCreated(delivery.secure_link_sent_at) })
  }
  return t('payroll.documents.secure_delivery.status.not_sent')
}

async function sendSecureLink(item: PayrollDocument): Promise<void> {
  if (sendingSecureLinkDocumentId.value !== null) return
  sendingSecureLinkDocumentId.value = item.id
  try {
    const result = await payrollApi.sendDocumentSecureLink(item.id)
    toast.success(t('payroll.documents.secure_delivery.link_sent', { recipient: result.recipient_masked }))
    const links = await payrollApi.documentSecureLinks(item.id)
    secureLinksByDocument.value.set(item.id, links)
  } catch (error: any) {
    const reason = error?.response?.data?.error?.reason as PayrollSecureDeliveryBlockedReason | undefined
    toast.error(
      secureDeliveryReasonMessage(reason)
      ?? apiErrorMessage(error, t('payroll.documents.secure_delivery.send_failed')),
    )
  } finally {
    sendingSecureLinkDocumentId.value = null
  }
}

async function revokeSecureLink(item: PayrollDocument, link: PayrollDocumentSecureLink): Promise<void> {
  if (revokingSecureLinkId.value !== null) return
  if (!window.confirm(t('payroll.documents.secure_delivery.revoke_confirm', { recipient: link.recipient_masked }))) return
  revokingSecureLinkId.value = link.id
  try {
    await payrollApi.revokeDocumentSecureLink(item.id, link.id)
    toast.success(t('payroll.documents.secure_delivery.link_revoked'))
    const links = await payrollApi.documentSecureLinks(item.id)
    secureLinksByDocument.value.set(item.id, links)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.secure_delivery.revoke_failed')))
  } finally {
    revokingSecureLinkId.value = null
  }
}

watch(activeTab, () => {
  clearBatchPoll()
  cancelCorrection()
  reload()
})
watch([selectedEmployeeId, year], cancelCorrection)
onMounted(load)
onBeforeUnmount(() => {
  clearBatchPoll()
  clearAnnualBatchPoll()
})
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
            :key="`batch-${revision.revision_id}`"
            type="button"
            data-test="generate-document-batch"
            :class="btnFilled('primary')"
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
      v-if="documentBatch"
      class="rounded-lg border p-4"
      :class="documentBatch.status === 'completed'
        ? 'border-success-500/30 bg-success-50'
        : documentBatch.status === 'failed'
          ? 'border-danger-500/30 bg-danger-50'
          : 'border-warning-500/30 bg-warning-50'"
      data-test="document-batch-report"
      role="status"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-900">{{ t('payroll.documents.batch_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-700">
            {{ t('payroll.documents.batch_progress', {
              done: documentBatch.succeeded_count,
              total: documentBatch.item_count,
              failed: documentBatch.failed_count,
            }) }}
          </p>
        </div>
        <span class="rounded-full bg-surface px-2.5 py-1 text-xs font-medium text-neutral-700">
          {{ t(`payroll.documents.batch_status.${documentBatch.status}`) }}
        </span>
      </div>
      <div class="mt-3 h-2 overflow-hidden rounded-full bg-neutral-200" role="progressbar" :aria-valuemin="0" :aria-valuemax="documentBatch.item_count" :aria-valuenow="documentBatch.succeeded_count">
        <div class="h-full bg-success-500 transition-all" :style="{ width: `${documentBatch.item_count ? Math.round(documentBatch.succeeded_count * 100 / documentBatch.item_count) : 0}%` }" />
      </div>
      <div v-if="documentBatch.bundle_document_id" class="mt-3 flex flex-wrap items-center gap-3">
        <button
          type="button"
          data-test="download-batch-bundle"
          :class="btnFilled('success')"
          :disabled="downloadingBundle"
          @click="downloadBundle()"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ t(downloadingBundle
            ? 'payroll.documents.batch_bundle_downloading'
            : 'payroll.documents.batch_bundle_download') }}
        </button>
        <p class="text-sm font-medium text-success-700">
          {{ documentBatch.bundle_filename || t('payroll.documents.batch_bundle_ready') }}
        </p>
      </div>
      <p
        v-if="bundleError"
        data-test="batch-bundle-error"
        class="mt-2 text-sm text-danger-700"
        role="alert"
      >
        {{ bundleError }}
      </p>
      <div v-if="documentBatchItems.length" class="mt-4 max-h-80 overflow-auto rounded-md border border-neutral-200 bg-surface">
        <div v-for="item in documentBatchItems" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-sm last:border-b-0" data-test="document-batch-item">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ item.employee_name }}</p>
            <p class="text-xs text-neutral-500">
              {{ t(`payroll.documents.batch_item_status.${item.status}`) }} · {{ t('payroll.documents.batch_attempts', { count: item.attempt_count }) }}
            </p>
            <p v-if="item.last_error_message" class="mt-1 break-words text-xs text-danger-700">{{ item.last_error_message }}</p>
          </div>
          <button
            v-if="item.status === 'failed' || item.status === 'retry_wait'"
            type="button"
            :class="btnOutline('warning')"
            :disabled="retryingBatchItemId !== null"
            data-test="retry-document-batch-item"
            @click="retryBatchItem(item)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll.documents.batch_retry') }}
          </button>
        </div>
      </div>
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
      <!--
        Rozsah vystavení. Do W29 šly roční dokumenty JEN po jednom — pro 500
        lidí 1500 kliknutí. „Za všechny" projede stejné tři akce nad celou
        firmou; průběh je vidět, jde zastavit a co se nepovedlo, je vypsané
        jménem.
      -->
      <div class="flex flex-wrap gap-2" role="group" :aria-label="t('payroll.documents.batch_annual.scope_label')">
        <button
          v-for="scope in (['selected', 'all'] as const)"
          :key="scope"
          type="button"
          :data-test="`annual-scope-${scope}`"
          :aria-pressed="batchScope === scope"
          :disabled="batchActive"
          :class="[
            'rounded-full border px-3 py-1.5 text-sm font-medium transition-colors',
            batchScope === scope
              ? 'border-payroll-500 bg-payroll-50 text-payroll-700'
              : 'border-neutral-300 text-neutral-600 hover:border-neutral-400',
          ]"
          @click="batchScope = scope"
        >
          {{ t(`payroll.documents.batch_annual.scope.${scope}`) }}
        </button>
      </div>

      <div class="mt-3 flex flex-wrap items-end gap-3">
        <div v-if="batchScope === 'selected'" class="min-w-64 flex-1">
          <span class="form-label">{{ t('payroll.documents.select_employee') }}</span>
          <PayrollPersonSearchSelect
            v-model="selectedEmployeeId"
            :placeholder="t('payroll.documents.select_employee_placeholder')"
            :clearable="false"
            :label="t('payroll.documents.select_employee')"
            data-test="payroll-documents-person"
          />
        </div>
        <p v-else class="min-w-64 flex-1 text-sm text-neutral-600">
          {{ t('payroll.documents.batch_annual.scope_all_hint', { year }) }}
        </p>
        <ActionBar :actions="annualActions" />
      </div>
      <!--
        Průběh dávky: bez něj by 500 lidí bylo jen dlouhé ticho. Zdrojem je
        SERVEROVÁ fronta, takže zpráva mluví pravdu i po znovunačtení stránky
        a „zavřít" jen schová panel, dávku nezruší.
      -->
      <section
        v-if="annualBatch"
        data-test="annual-batch-report"
        class="mt-4 rounded-lg border p-4"
        :class="annualBatch.status === 'completed'
          ? (annualBatch.failed_count > 0
            ? 'border-danger-500/30 bg-danger-50'
            : 'border-success-500/30 bg-success-50')
          : 'border-payroll-500/30 bg-payroll-50'"
        role="status"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-neutral-900">
              {{ t('payroll.documents.batch_annual.progress', {
                done: batchDone,
                total: batchTotal,
              }) }}
            </p>
            <p class="mt-1 text-xs text-neutral-600">
              {{ t(`payroll.documents.batch_annual.status.${annualBatch.status}`) }}
              <template v-if="annualBatchOpen">
                · {{ t('payroll.documents.batch_annual.server_hint') }}
              </template>
            </p>
          </div>
          <button
            type="button"
            data-test="annual-batch-dismiss"
            :class="btnOutline('neutral')"
            @click="dismissAnnualBatch"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('payroll.documents.batch_annual.dismiss') }}
          </button>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-neutral-200" role="progressbar" :aria-valuemin="0" :aria-valuemax="batchTotal" :aria-valuenow="batchDone">
          <div
            class="h-full bg-payroll-500 transition-all"
            :style="{ width: `${batchTotal ? Math.round(batchDone * 100 / batchTotal) : 0}%` }"
          />
        </div>
        <div v-if="batchSkipped.length" class="mt-3 text-sm text-neutral-700" data-test="annual-batch-skipped">
          <p class="font-medium">{{ t('payroll.documents.batch_annual.skipped_title', { count: batchSkipped.length }) }}</p>
          <p class="mt-1 leading-snug">{{ batchSkipped.map(row => row.name).join(', ') }}</p>
          <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.documents.batch_annual.skipped_hint') }}</p>
        </div>
        <div v-if="batchFailures.length" class="mt-3 text-sm text-danger-700" data-test="annual-batch-failures">
          <p class="font-medium">{{ t('payroll.documents.batch_annual.failed_title', { count: batchFailures.length }) }}</p>
          <ul class="mt-1 space-y-1">
            <li v-for="row in batchFailures" :key="row.id" class="flex flex-wrap items-center justify-between gap-2">
              <span>
                <span class="font-medium">{{ row.name }}</span> - {{ row.reason }}
              </span>
              <button
                type="button"
                data-test="retry-annual-batch-item"
                :class="btnOutline('warning')"
                :disabled="retryingAnnualItemId !== null"
                @click="retryAnnualBatchItem(row.id)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.cycle" />
                </svg>
                {{ t('payroll.documents.batch_annual.retry') }}
              </button>
            </li>
          </ul>
        </div>
      </section>

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
                  <div class="flex flex-col items-end gap-1.5">
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
                    <template v-if="item.employee_id !== null">
                      <p data-test="secure-delivery-status" class="text-xs text-neutral-500">
                        {{ secureDeliveryStatusText(item) }}
                      </p>
                      <div class="flex flex-wrap justify-end gap-1.5">
                        <button
                          type="button"
                          data-test="send-secure-link"
                          :class="btnOutlineSm('primary')"
                          :disabled="sendingSecureLinkDocumentId !== null"
                          @click="sendSecureLink(item)"
                        >
                          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path :d="ICONS.send" />
                          </svg>
                          {{
                            sendingSecureLinkDocumentId === item.id
                              ? t('payroll.documents.secure_delivery.sending_link')
                              : t('payroll.documents.secure_delivery.send_link')
                          }}
                        </button>
                        <button
                          v-if="liveSecureLink(item.id)"
                          type="button"
                          data-test="revoke-secure-link"
                          :class="btnOutlineSm('danger')"
                          :disabled="revokingSecureLinkId !== null"
                          @click="revokeSecureLink(item, liveSecureLink(item.id)!)"
                        >
                          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path :d="ICONS.x" />
                          </svg>
                          {{
                            revokingSecureLinkId === liveSecureLink(item.id)?.id
                              ? t('payroll.documents.secure_delivery.revoking')
                              : t('payroll.documents.secure_delivery.revoke_link')
                          }}
                        </button>
                      </div>
                      <p v-if="liveSecureLink(item.id)" class="max-w-[14rem] text-right text-[11px] text-neutral-400">
                        {{ t('payroll.documents.secure_delivery.link_hidden_hint') }}
                      </p>
                    </template>
                  </div>
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
          <template v-if="item.employee_id !== null">
            <p data-test="secure-delivery-status" class="mt-3 text-xs text-neutral-500">
              {{ secureDeliveryStatusText(item) }}
            </p>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
              <button
                type="button"
                data-test="send-secure-link"
                :class="[btnOutlineSm('primary'), 'flex-1 justify-center']"
                :disabled="sendingSecureLinkDocumentId !== null"
                @click="sendSecureLink(item)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path :d="ICONS.send" />
                </svg>
                {{
                  sendingSecureLinkDocumentId === item.id
                    ? t('payroll.documents.secure_delivery.sending_link')
                    : t('payroll.documents.secure_delivery.send_link')
                }}
              </button>
              <button
                v-if="liveSecureLink(item.id)"
                type="button"
                data-test="revoke-secure-link"
                :class="[btnOutlineSm('danger'), 'flex-1 justify-center']"
                :disabled="revokingSecureLinkId !== null"
                @click="revokeSecureLink(item, liveSecureLink(item.id)!)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path :d="ICONS.x" />
                </svg>
                {{
                  revokingSecureLinkId === liveSecureLink(item.id)?.id
                    ? t('payroll.documents.secure_delivery.revoking')
                    : t('payroll.documents.secure_delivery.revoke_link')
                }}
              </button>
            </div>
            <p v-if="liveSecureLink(item.id)" class="mt-1 text-[11px] text-neutral-400">
              {{ t('payroll.documents.secure_delivery.link_hidden_hint') }}
            </p>
          </template>
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

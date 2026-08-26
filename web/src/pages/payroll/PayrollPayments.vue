<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { payrollApi, type PayrollRun } from '@/api/payroll'
import {
  payrollPaymentsApi,
  type PayrollPayerOption,
  type PayrollPaymentAllocation,
  type PayrollPaymentBatch,
  type PayrollPaymentEvidence,
  type PayrollPaymentExport,
  type PayrollPaymentLiability,
  type PayrollPaymentLiabilityState,
  type PayrollPaymentMatch,
} from '@/api/payrollPayments'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import {
  btnFilled,
  btnFilledSm,
  btnOutline,
  btnOutlineSm,
  disabledTitle,
  BTN_DISABLED_NOTE,
  ICONS,
} from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
// Formátování je sdílené (useFormat) — místní kopie se lišily locale i tvarem
// data od zbytku aplikace, viz komentář u `formatMoneyMinor`.
import { formatDate, formatDateTime, formatMoneyMinor as formatMoney } from '@/composables/useFormat'
import { localPayrollPeriod } from './payrollComponentsUi'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const requestedPeriod = typeof route.query.period === 'string'
  && /^(20|21)\d{2}-(0[1-9]|1[0-2])$/.test(route.query.period)
  ? route.query.period
  : null
const parsedRequestedRunId = typeof route.query.run === 'string'
  && /^[1-9]\d*$/.test(route.query.run)
  ? Number(route.query.run)
  : null
const requestedRunId = parsedRequestedRunId !== null
  && Number.isSafeInteger(parsedRequestedRunId)
  ? parsedRequestedRunId
  : null
const runShortcutRequested = route.query.focus === 'bank-order'
  && requestedRunId !== null
const runShortcutApplied = ref(false)
const runShortcutState = ref<'ready' | 'empty' | null>(null)
const period = ref(requestedPeriod ?? localPayrollPeriod())
const activeTab = ref<'liabilities' | 'batches' | 'settlements'>('liabilities')
const loading = ref(true)
/*
 * Selhalo načtení? Pak o závazcích nevíme NIC — a to je něco jiného než „za
 * tohle období žádné nejsou". Toast za pár vteřin zmizí a bez tohohle příznaku
 * by na obrazovce zůstal prázdný stav, který tvrdí, že je hotovo.
 */
const loadFailed = ref(false)
const materializing = ref(false)
const creatingBatch = ref(false)
const generatingBatchId = ref<number | null>(null)
const downloadingExportId = ref<number | null>(null)
const items = ref<PayrollPaymentLiability[]>([])
const periodTotals = ref({ amount_minor: 0, allocated_minor: 0, settled_minor: 0 })
const runs = ref<PayrollRun[]>([])
const payerOptions = ref<PayrollPayerOption[]>([])
const batches = ref<PayrollPaymentBatch[]>([])
/*
 * Nabídky pro picker (alokace, bankovní a pokladní důkazy) chodí ze serveru
 * OŘEZANÉ. Krátký seznam přijde celý a picker pak filtruje v prohlížeči — bez
 * jediného dalšího volání. Jakmile server přizná oříznutí, přepne se ten
 * konkrétní picker do serverového hledání a řekne uživateli, že nabídka není
 * úplná; mlčky oříznutý seznam by tvrdil „nic dalšího neexistuje".
 */
const allocations = ref<PayrollPaymentAllocation[]>([])
const allocationsTruncated = ref(false)
const paymentMatches = ref<PayrollPaymentMatch[]>([])
const reversibleMatchOptions = ref<PayrollPaymentMatch[]>([])
const bankEvidence = ref<PayrollPaymentEvidence[]>([])
const bankEvidenceTruncated = ref(false)
const cashEvidence = ref<PayrollPaymentEvidence[]>([])
const cashEvidenceTruncated = ref(false)
const allocationSearchResults = ref<PayrollPaymentAllocation[]>([])
const allocationSearchTruncated = ref(false)
const allocationSearching = ref(false)
const matchEvidenceSearchResults = ref<PayrollPaymentEvidence[]>([])
const matchEvidenceSearchTruncated = ref(false)
const matchEvidenceSearching = ref(false)
const reversalEvidenceSearchResults = ref<PayrollPaymentEvidence[]>([])
const reversalEvidenceSearchTruncated = ref(false)
const reversalEvidenceSearching = ref(false)
/*
 * Vybraná položka se drží jako CELÝ objekt, ne jen id: v serverovém režimu
 * zmizí z nabídky, jakmile uživatel napíše jiný dotaz, a bez uloženého objektu
 * by spadly limity částky i popisek vybrané položky.
 */
const pickedAllocation = ref<PayrollPaymentAllocation | null>(null)
const pickedMatchEvidence = ref<PayrollPaymentEvidence | null>(null)
const pickedReversalEvidence = ref<PayrollPaymentEvidence | null>(null)
const selectedIds = ref<number[]>([])
const exportFormat = ref<'abo' | 'sepa' | 'manual' | null>(null)
const payerReference = ref<string | null>(null)
const selectedAllocationId = ref<number | null>(null)
const selectedMatchEvidence = ref<string | null>(null)
const matchAmount = ref('')
const matching = ref(false)
const selectedSourceMatchId = ref<number | null>(null)
const selectedReversalEvidence = ref<string | null>(null)
const reversalAmount = ref('')
const reversing = ref(false)
let loadSequence = 0
const pendingExportKeys = new Map<number, string>()
const pendingReconciliationKeys = new Map<string, string>()

const COLUMNS: ColumnDef[] = [
  { key: 'recipient', labelKey: 'payroll.payments.recipient_label', required: true },
  { key: 'kind', labelKey: 'payroll.payments.kind_label' },
  { key: 'destination', labelKey: 'payroll.payments.destination' },
  { key: 'due_on', labelKey: 'payroll.payments.due_on' },
  { key: 'amount', labelKey: 'payroll.payments.amount', required: true },
  { key: 'settled', labelKey: 'payroll.payments.settled' },
  { key: 'status', labelKey: 'payroll.payments.status' },
]
const tbl = useTablePrefs('payroll-payments', COLUMNS)

const pageSize = 50
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

function goToPage(nextPage: number): void {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

// Historie párování má vlastní stránkování — je to jiný seznam než závazky
// a přepnutí strany v jednom nesmí přehazovat druhý.
const matchPageSize = 25
const matchTotal = ref(0)
const matchOffset = ref(0)
const matchPage = computed(() => Math.floor(matchOffset.value / matchPageSize) + 1)

function goToMatchPage(nextPage: number): void {
  matchOffset.value = Math.max(0, (nextPage - 1) * matchPageSize)
  void load()
}

/** Změna období mění obsah seznamu, takže stránka musí zpět na začátek. */
function reload(): void {
  offset.value = 0
  matchOffset.value = 0
  void load()
}

const materializableRevisions = computed(() => {
  const seen = new Set<number>()
  return runs.value.filter(run => {
    if (run.revision_id === null || seen.has(run.revision_id)) return false
    if (!['approved', 'posted', 'payment_ready', 'paid', 'closed'].includes(run.status)) return false
    if (!run.payment_materialization_supported) return false
    seen.add(run.revision_id)
    return true
  })
})
const canMaterialize = computed(() =>
  auth.canWrite('payroll.payments') && materializableRevisions.value.length > 0,
)
/*
 * Proč je „Připravit závazky" zašedlé. Vrací `null`, když akce jde spustit —
 * tlačítko pak žádnou omluvu nepotřebuje. Po selhání načtení je odpověď jiná:
 * o revizích nic nevíme, takže neříkáme „schvalte revizi" (třeba už schválená
 * je), ale pravdu — data chybí.
 */
const materializeBlockedReason = computed<string | null>(() => {
  if (canMaterialize.value) return null
  if (loadFailed.value) return t('payroll.payments.materialize_blocked_unknown')
  return t('payroll.payments.materialize_blocked')
})
/*
 * Součty za celé období počítá server. Sečíst je z `items` by po zavedení
 * stránkování znamenalo hlásit jako „celkem" jen tolik, kolik se zrovna vešlo
 * na obrazovku. Znaménko příchozích závazků má server vyřešené stejně jako
 * `signed()`.
 */
const totals = computed(() => ({
  amount: periodTotals.value.amount_minor,
  allocated: periodTotals.value.allocated_minor,
  settled: periodTotals.value.settled_minor,
}))
const selectedItems = computed(() => {
  const ids = new Set(selectedIds.value)
  return items.value.filter(item => ids.has(item.id))
})
const selectionAnchor = computed(() => selectedItems.value[0] ?? null)
const selectedTotalMinor = computed(() => selectedItems.value.reduce(
  (sum, item) => sum + remainingMinor(item),
  0,
))
const payerSelectOptions = computed(() => {
  const anchor = selectionAnchor.value
  if (!anchor) return []
  if (anchor.recipient_kind === 'cash') {
    return [{
      value: 'cash',
      label: t('payroll.payments.batch.cash_payer'),
      secondary: t('payroll.payments.recipient.cash'),
    }]
  }
  return payerOptions.value
    .filter(option =>
      option.currency_code === anchor.currency_code
      && exportFormat.value !== null
      && exportFormat.value !== 'manual'
      && option.export_formats.includes(exportFormat.value),
    )
    .map(option => ({
      value: option.reference,
      label: [option.bank_name, option.masked_account].filter(Boolean).join(' · '),
      secondary: option.currency_code,
    }))
})
const formatSelectOptions = computed(() => {
  const anchor = selectionAnchor.value
  if (!anchor) return []
  if (anchor.recipient_kind === 'cash') {
    return [{
      value: 'manual' as const,
      label: t('payroll.payments.batch.format.manual'),
    }]
  }
  if (anchor.currency_code === 'CZK') {
    return [{
      value: 'abo' as const,
      label: t('payroll.payments.batch.format.abo'),
    }]
  }
  if (anchor.currency_code === 'EUR') {
    return [{
      value: 'sepa' as const,
      label: t('payroll.payments.batch.format.sepa'),
    }]
  }
  return []
})
const canCreateBatch = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedItems.value.length > 0
  && exportFormat.value !== null
  && payerReference.value !== null,
)
const allocationPool = computed(() => {
  const pool = new Map<number, PayrollPaymentAllocation>()
  for (const item of allocations.value) pool.set(item.id, item)
  for (const item of allocationSearchResults.value) pool.set(item.id, item)
  if (pickedAllocation.value) pool.set(pickedAllocation.value.id, pickedAllocation.value)
  return pool
})
const selectedAllocation = computed(() =>
  allocationPool.value.get(selectedAllocationId.value ?? -1) ?? null,
)
// Nabídka storna se bere ze serverem vrácené množiny vratných událostí, ne
// ze zobrazené stránky historie — jinak by šlo stornovat jen to, co má
// uživatel zrovna na obrazovce.
const reversibleMatches = computed(() => reversibleMatchOptions.value.filter(
  item => item.event_kind === 'matched' && item.reversible_minor > 0,
))
const selectedSourceMatch = computed(() =>
  reversibleMatches.value.find(item => item.id === selectedSourceMatchId.value)
  ?? null,
)
// Zúžení nabídky důkazů podle měny, směru a použitelnosti je STEJNÉ pravidlo
// jako v serverovém hledání (`usage`). Tady se uplatní na lokální seznam,
// tam v dotazu — jinak by ze serverem vybrané dvacítky po klientském filtru
// zbylo prázdno, které vypadá jako „žádný důkaz neexistuje".
function matchEvidenceMatches(
  item: PayrollPaymentEvidence,
  allocation: PayrollPaymentAllocation,
): boolean {
  return item.currency_code === allocation.currency_code
    && item.direction === allocation.direction
    && item.available_match_minor > 0
    && (item.kind !== 'cash' || item.status === 'posted')
}
const matchEvidenceRemote = computed(() => selectedAllocation.value?.channel === 'bank'
  ? bankEvidenceTruncated.value
  : cashEvidenceTruncated.value)
const matchEvidenceCandidates = computed(() => {
  const allocation = selectedAllocation.value
  if (!allocation) return []
  if (matchEvidenceRemote.value) return matchEvidenceSearchResults.value
  const evidence = allocation.channel === 'bank'
    ? bankEvidence.value
    : cashEvidence.value
  return evidence.filter(item => matchEvidenceMatches(item, allocation))
})
const reversalEvidenceRemote = computed(() =>
  selectedSourceMatch.value?.evidence_kind === 'cash'
    ? cashEvidenceTruncated.value
    : bankEvidenceTruncated.value)
const reversalEvidenceCandidates = computed(() => {
  const match = selectedSourceMatch.value
  if (!match) return []
  if (reversalEvidenceRemote.value) return reversalEvidenceSearchResults.value
  if (match.evidence_kind === 'cash') {
    return cashEvidence.value.filter(item =>
      item.cash_document_id === match.cash_document_id
      && item.status === 'reversed'
      && item.available_reversal_minor > 0,
    )
  }
  const direction = match.allocation_direction === 'outgoing'
    ? 'incoming'
    : 'outgoing'
  return bankEvidence.value.filter(item =>
    item.currency_code === match.allocation_currency_code
    && item.direction === direction
    && item.available_reversal_minor > 0,
  )
})
const allocationOptionSource = computed(() => allocationsTruncated.value
  ? allocationSearchResults.value
  : allocations.value)
const allocationSelectOptions = computed(() => allocationOptionSource.value
  .map(item => ({
    value: item.id,
    label: item.employee_name || t('payroll.payments.company'),
    secondary: `${kindLabel(item.liability_kind)} · ${formatMoney(
      item.remaining_minor,
      item.currency_code,
    )}`,
  })))
const matchEvidenceSelectOptions = computed(() =>
  matchEvidenceCandidates.value.map(evidenceOption),
)
const sourceMatchSelectOptions = computed(() => reversibleMatches.value.map(
  item => ({
    value: item.id,
    label: item.employee_name || t('payroll.payments.company'),
    secondary: `${formatDate(item.actual_payment_date)} · ${formatMoney(
      item.reversible_minor,
      item.evidence_currency_code,
    )}`,
  }),
))
const reversalEvidenceSelectOptions = computed(() =>
  reversalEvidenceCandidates.value.map(evidenceOption),
)
// Vybraný důkaz se hledá v nabídce, a když v ní není (server ji mezitím zúžil
// jiným dotazem), použije se zapamatovaný objekt. Bez toho by po přepsání
// hledání spadl limit částky na nulu a tlačítko by zšedlo bez důvodu.
const selectedMatchEvidenceItem = computed(() =>
  matchEvidenceCandidates.value.find(
    item => evidenceKey(item) === selectedMatchEvidence.value,
  )
  ?? (pickedMatchEvidence.value
    && evidenceKey(pickedMatchEvidence.value) === selectedMatchEvidence.value
    ? pickedMatchEvidence.value
    : null),
)
const selectedReversalEvidenceItem = computed(() =>
  reversalEvidenceCandidates.value.find(
    item => evidenceKey(item) === selectedReversalEvidence.value,
  )
  ?? (pickedReversalEvidence.value
    && evidenceKey(pickedReversalEvidence.value) === selectedReversalEvidence.value
    ? pickedReversalEvidence.value
    : null),
)
const matchLimitMinor = computed(() => Math.min(
  selectedAllocation.value?.remaining_minor ?? 0,
  selectedMatchEvidenceItem.value?.available_match_minor ?? 0,
))
const reversalLimitMinor = computed(() => Math.min(
  selectedSourceMatch.value?.reversible_minor ?? 0,
  selectedReversalEvidenceItem.value?.available_reversal_minor ?? 0,
))
const canMatch = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedAllocation.value !== null
  && selectedMatchEvidenceItem.value !== null
  && parseMinor(matchAmount.value) > 0
  && parseMinor(matchAmount.value) <= matchLimitMinor.value,
)
const canReverse = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedSourceMatch.value !== null
  && selectedReversalEvidenceItem.value !== null
  && parseMinor(reversalAmount.value) > 0
  && parseMinor(reversalAmount.value) <= reversalLimitMinor.value,
)

function signed(item: PayrollPaymentLiability, amount: number): number {
  return item.direction === 'incoming' ? -amount : amount
}

function remainingMinor(item: PayrollPaymentLiability): number {
  return Math.max(0, item.amount_minor - item.allocated_minor)
}

function isSelectable(item: PayrollPaymentLiability): boolean {
  if (!isOpenBatchable(item)) return false
  const anchor = selectionAnchor.value
  return !anchor
    || anchor.id === item.id
    || isSameBatchGroup(anchor, item)
}

function isOpenBatchable(item: PayrollPaymentLiability): boolean {
  return !(
    item.batch_eligibility !== 'ready'
    || !['open', 'partially_batched'].includes(item.state)
    || remainingMinor(item) <= 0
  )
}

function isSameBatchGroup(
  anchor: PayrollPaymentLiability,
  item: PayrollPaymentLiability,
): boolean {
  return anchor.due_on === item.due_on
    && anchor.currency_code === item.currency_code
    && anchor.recipient_kind === item.recipient_kind
}

function toggleSelection(item: PayrollPaymentLiability): void {
  if (!isSelectable(item) && !selectedIds.value.includes(item.id)) return
  selectedIds.value = selectedIds.value.includes(item.id)
    ? selectedIds.value.filter(id => id !== item.id)
    : [...selectedIds.value, item.id]
}

function toggleAll(): void {
  if (selectedIds.value.length > 0) {
    selectedIds.value = []
    return
  }
  const first = items.value.find(item => isSelectable(item))
  if (!first) return
  selectedIds.value = [first.id]
  selectedIds.value = items.value
    .filter(item => isSelectable(item))
    .map(item => item.id)
}

function isSelected(id: number): boolean {
  return selectedIds.value.includes(id)
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 1,
  }).format(bytes / 1024)} kB`
}

function evidenceKey(evidence: PayrollPaymentEvidence): string {
  return evidence.kind === 'bank'
    ? `bank:${evidence.bank_statement_id}:${evidence.bank_transaction_id}`
    : `cash:${evidence.cash_document_id}`
}

function evidenceOption(evidence: PayrollPaymentEvidence) {
  return {
    value: evidenceKey(evidence),
    label: `${formatDate(evidence.date)} · ${formatMoney(
      evidence.amount_minor,
      evidence.currency_code,
    )}`,
    secondary: evidence.description
      || evidence.reference
      || t(`payroll.payments.recipient.${evidence.kind}`),
  }
}

/**
 * Serverové hledání v nabídce pickeru.
 *
 * Volá se jen tehdy, když server nabídku oříznul. Krátký seznam zůstává
 * kompletní v prohlížeči, takže výběr v něm nestojí ani jeden dotaz navíc.
 */
async function searchAllocationOptions(query: string): Promise<void> {
  if (!allocationsTruncated.value) return
  allocationSearching.value = true
  try {
    const result = await payrollPaymentsApi.searchOptions({
      period: period.value,
      kind: 'allocations',
      q: query,
    })
    allocationSearchResults.value = result.items as PayrollPaymentAllocation[]
    allocationSearchTruncated.value = result.truncated
  } catch (error) {
    allocationSearchResults.value = []
    allocationSearchTruncated.value = false
    toast.error(apiErrorMessage(error, t('payroll.payments.options_failed')))
  } finally {
    allocationSearching.value = false
  }
}

async function searchMatchEvidenceOptions(query: string): Promise<void> {
  const allocation = selectedAllocation.value
  if (!allocation || !matchEvidenceRemote.value) return
  matchEvidenceSearching.value = true
  try {
    const result = await payrollPaymentsApi.searchOptions({
      period: period.value,
      kind: allocation.channel === 'bank' ? 'bank_evidence' : 'cash_evidence',
      q: query,
      currency: allocation.currency_code,
      direction: allocation.direction,
      usage: 'match',
    })
    matchEvidenceSearchResults.value = result.items as PayrollPaymentEvidence[]
    matchEvidenceSearchTruncated.value = result.truncated
  } catch (error) {
    matchEvidenceSearchResults.value = []
    matchEvidenceSearchTruncated.value = false
    toast.error(apiErrorMessage(error, t('payroll.payments.options_failed')))
  } finally {
    matchEvidenceSearching.value = false
  }
}

async function searchReversalEvidenceOptions(query: string): Promise<void> {
  const match = selectedSourceMatch.value
  if (!match || !reversalEvidenceRemote.value) return
  reversalEvidenceSearching.value = true
  try {
    const result = await payrollPaymentsApi.searchOptions({
      period: period.value,
      kind: match.evidence_kind === 'cash' ? 'cash_evidence' : 'bank_evidence',
      q: query,
      usage: 'reversal',
      ...(match.evidence_kind === 'cash'
        ? { cash_document_id: match.cash_document_id ?? 0 }
        : {
            currency: match.allocation_currency_code,
            direction: match.allocation_direction === 'outgoing'
              ? 'incoming' as const
              : 'outgoing' as const,
          }),
    })
    reversalEvidenceSearchResults.value = result.items as PayrollPaymentEvidence[]
    reversalEvidenceSearchTruncated.value = result.truncated
  } catch (error) {
    reversalEvidenceSearchResults.value = []
    reversalEvidenceSearchTruncated.value = false
    toast.error(apiErrorMessage(error, t('payroll.payments.options_failed')))
  } finally {
    reversalEvidenceSearching.value = false
  }
}

function parseMinor(value: string): number {
  const normalized = value.trim().replace(/\s+/g, '').replace(',', '.')
  const match = normalized.match(/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/)
  if (!match) return 0
  const whole = Number(match[1])
  const fraction = Number((match[2] || '').padEnd(2, '0'))
  if (!Number.isSafeInteger(whole) || !Number.isSafeInteger(fraction)) return 0
  const minor = (whole * 100) + fraction
  return Number.isSafeInteger(minor) ? minor : 0
}

function minorInput(amountMinor: number): string {
  const whole = Math.floor(amountMinor / 100)
  const fraction = String(amountMinor % 100).padStart(2, '0')
  return `${whole},${fraction}`
}

function evidencePayload(evidence: PayrollPaymentEvidence) {
  if (evidence.kind === 'bank') {
    return {
      kind: 'bank' as const,
      bank_statement_id: evidence.bank_statement_id!,
      bank_transaction_id: evidence.bank_transaction_id!,
    }
  }
  return {
    kind: 'cash' as const,
    cash_document_id: evidence.cash_document_id!,
  }
}

function reconciliationKey(scope: string): string {
  const existing = pendingReconciliationKeys.get(scope)
  if (existing) return existing
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  const key = `payroll-${scope}-${random}`
  pendingReconciliationKeys.set(scope, key)
  return key
}

function kindLabel(kind: string): string {
  const key = `payroll.payments.kind.${kind}`
  const translated = t(key)
  return translated === key ? kind : translated
}

function recipientName(item: PayrollPaymentLiability): string {
  return item.recipient_name
    || item.employee_name
    || t('payroll.payments.company')
}

function stateLabel(state: PayrollPaymentLiabilityState): string {
  return t(`payroll.payments.state.${state}`)
}

function stateClass(state: PayrollPaymentLiabilityState): string {
  if (state === 'settled') return 'bg-success-50 text-success-700'
  if (state === 'partially_settled') return 'bg-warning-50 text-warning-700'
  if (state === 'batched' || state === 'partially_batched') {
    return 'bg-payroll-50 text-payroll-700'
  }
  return 'bg-neutral-100 text-neutral-700'
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  loading.value = true
  loadFailed.value = false
  try {
    const [
      liabilityList,
      runList,
      payerList,
      batchList,
      reconciliation,
    ] = await Promise.all([
      payrollPaymentsApi.liabilities(requestedPeriod, { limit: pageSize, offset: offset.value }),
      payrollApi.runs(requestedPeriod),
      payrollPaymentsApi.payerOptions(),
      payrollPaymentsApi.batches(requestedPeriod),
      payrollPaymentsApi.reconciliation(requestedPeriod, {
        limit: matchPageSize,
        offset: matchOffset.value,
      }),
    ])
    if (sequence === loadSequence && requestedPeriod === period.value) {
      items.value = liabilityList.items
      total.value = liabilityList.total
      periodTotals.value = liabilityList.totals
      runs.value = runList
      payerOptions.value = payerList
      batches.value = batchList.items
      allocations.value = reconciliation.allocations
      allocationsTruncated.value = reconciliation.allocations_truncated
      paymentMatches.value = reconciliation.matches
      matchTotal.value = reconciliation.matches_total
      reversibleMatchOptions.value = reconciliation.reversible_matches
      bankEvidence.value = reconciliation.bank_evidence
      bankEvidenceTruncated.value = reconciliation.bank_evidence_truncated
      cashEvidence.value = reconciliation.cash_evidence
      cashEvidenceTruncated.value = reconciliation.cash_evidence_truncated
      allocationSearchResults.value = []
      allocationSearchTruncated.value = false
      matchEvidenceSearchResults.value = []
      matchEvidenceSearchTruncated.value = false
      reversalEvidenceSearchResults.value = []
      reversalEvidenceSearchTruncated.value = false
      // Výběr se ruší jen tehdy, když nabídka NENÍ oříznutá. U oříznuté je
      // „není v poslané nabídce" bezcenná informace: alokace může existovat
      // a jen se do stropu nevešla.
      if (!reconciliation.allocations_truncated
        && !reconciliation.allocations.some(
          item => item.id === selectedAllocationId.value,
        )
      ) {
        selectedAllocationId.value = null
        pickedAllocation.value = null
      }
      if (!reconciliation.reversible_matches.some(
        item => item.id === selectedSourceMatchId.value,
      )) {
        selectedSourceMatchId.value = null
      }
      selectedIds.value = selectedIds.value.filter(id =>
        liabilityList.items.some(item => item.id === id),
      )
      if (runShortcutRequested && !runShortcutApplied.value) {
        const candidates = liabilityList.items.filter(item =>
          item.run_id === requestedRunId && isOpenBatchable(item),
        )
        const anchor = candidates[0]
        selectedIds.value = anchor
          ? candidates
            .filter(item => isSameBatchGroup(anchor, item))
            .map(item => item.id)
          : []
        runShortcutState.value = selectedIds.value.length > 0
          ? 'ready'
          : 'empty'
        runShortcutApplied.value = true
      }
    }
  } catch (error) {
    if (sequence === loadSequence) {
      /*
       * Kolekce se schválně NEVYNULUJÍ. Prázdné pole se v šabloně nedá odlišit
       * od „období nemá žádné závazky", takže by stránka po výpadku sítě
       * sebejistě tvrdila nepravdu. Poslední úspěšně načtená data jsou pořád
       * lepší informace než prázdno; nad nimi se vykreslí `loadFailed` stav
       * s nabídkou opakování. Výběr se ale ruší — potvrzovat dávku nad daty,
       * o kterých nevíme, jestli pořád platí, je past.
       */
      selectedIds.value = []
      loadFailed.value = true
      toast.error(apiErrorMessage(error, t('payroll.payments.load_failed')))
    }
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function createBatch(): Promise<void> {
  if (!canCreateBatch.value || creatingBatch.value) return
  creatingBatch.value = true
  try {
    const result = await payrollPaymentsApi.createBatch({
      export_format: exportFormat.value!,
      payer_reference: payerReference.value!,
      items: selectedItems.value.map(item => ({
        liability_id: item.id,
        amount_minor: remainingMinor(item),
      })),
    })
    toast.success(t(
      result.replayed
        ? 'payroll.payments.batch.replayed'
        : 'payroll.payments.batch.created',
      { count: result.declared_item_count },
    ))
    selectedIds.value = []
    activeTab.value = 'batches'
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.create_failed'),
    ))
  } finally {
    creatingBatch.value = false
  }
}

function idempotencyKey(batchId: number): string {
  const pending = pendingExportKeys.get(batchId)
  if (pending) return pending
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  const key = `payroll-export-${batchId}-${random}`
  pendingExportKeys.set(batchId, key)
  return key
}

async function generateExport(batch: PayrollPaymentBatch): Promise<void> {
  if (
    !auth.canWrite('payroll.payments')
    || batch.export_format === 'manual'
    || generatingBatchId.value !== null
  ) return
  generatingBatchId.value = batch.id
  try {
    await payrollPaymentsApi.generateExport(
      batch.id,
      idempotencyKey(batch.id),
    )
    pendingExportKeys.delete(batch.id)
    toast.success(t('payroll.payments.batch.export_created'))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.export_failed'),
    ))
  } finally {
    generatingBatchId.value = null
  }
}

async function downloadExport(file: PayrollPaymentExport): Promise<void> {
  if (downloadingExportId.value !== null) return
  downloadingExportId.value = file.id
  try {
    const grant = await payrollPaymentsApi.createDownloadGrant(file.id)
    const blob = await payrollPaymentsApi.downloadExport(grant.token)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.suggested_filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.download_failed'),
    ))
  } finally {
    downloadingExportId.value = null
  }
}

async function materialize(): Promise<void> {
  if (!canMaterialize.value || materializing.value) return
  materializing.value = true
  let created = 0
  let succeeded = 0
  const failures: unknown[] = []
  for (const run of materializableRevisions.value) {
    if (run.revision_id === null) continue
    try {
      const result = await payrollPaymentsApi.materializeLiabilities(
        run.revision_id,
      )
      created += result.created_count
      succeeded += 1
      failures.push(...result.preparation_issues.map(issue =>
        new Error(issue.message),
      ))
    } catch (error) {
      failures.push(error)
    }
  }
  try {
    if (succeeded > 0) {
      toast.success(t(
        created > 0
          ? 'payroll.payments.materialized'
          : 'payroll.payments.materialized_replay',
        { count: created },
      ))
    }
    if (failures.length > 0) {
      const detail = apiErrorMessage(
        failures[0],
        t('payroll.payments.materialize_failed'),
      )
      toast.error(t(
        'payroll.payments.materialize_partial_failed',
        { count: failures.length, detail },
      ))
    }
    if (succeeded > 0) {
      await load()
    }
  } finally {
    materializing.value = false
  }
}

async function matchPayment(): Promise<void> {
  const allocation = selectedAllocation.value
  const evidence = selectedMatchEvidenceItem.value
  const amountMinor = parseMinor(matchAmount.value)
  if (!allocation || !evidence || !canMatch.value || matching.value) return
  const scope = `match-${allocation.id}-${evidenceKey(evidence)}-${amountMinor}`
  matching.value = true
  try {
    await payrollPaymentsApi.match({
      allocation_id: allocation.id,
      amount_minor: amountMinor,
      evidence: evidencePayload(evidence),
      idempotency_key: reconciliationKey(scope),
    })
    pendingReconciliationKeys.delete(scope)
    toast.success(t('payroll.payments.settlements.match_success'))
    selectedAllocationId.value = null
    selectedMatchEvidence.value = null
    matchAmount.value = ''
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.settlements.match_failed'),
    ))
  } finally {
    matching.value = false
  }
}

async function reversePayment(): Promise<void> {
  const source = selectedSourceMatch.value
  const evidence = selectedReversalEvidenceItem.value
  const amountMinor = parseMinor(reversalAmount.value)
  if (!source || !evidence || !canReverse.value || reversing.value) return
  const scope = `reverse-${source.id}-${evidenceKey(evidence)}-${amountMinor}`
  reversing.value = true
  try {
    await payrollPaymentsApi.reverse({
      source_match_id: source.id,
      amount_minor: amountMinor,
      evidence: evidencePayload(evidence),
      idempotency_key: reconciliationKey(scope),
    })
    pendingReconciliationKeys.delete(scope)
    toast.success(t('payroll.payments.settlements.reverse_success'))
    selectedSourceMatchId.value = null
    selectedReversalEvidence.value = null
    reversalAmount.value = ''
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.settlements.reverse_failed'),
    ))
  } finally {
    reversing.value = false
  }
}

watch(selectedIds, () => {
  const anchor = selectionAnchor.value
  if (!anchor) {
    exportFormat.value = null
    payerReference.value = null
    return
  }
  const nextFormat = anchor.recipient_kind === 'cash'
    ? 'manual'
    : anchor.currency_code === 'CZK'
      ? 'abo'
      : anchor.currency_code === 'EUR'
        ? 'sepa'
        : null
  if (!formatSelectOptions.value.some(option =>
    option.value === exportFormat.value,
  )) {
    exportFormat.value = nextFormat
  }
  const options = payerSelectOptions.value
  if (!options.some(option => option.value === payerReference.value)) {
    payerReference.value = options[0]?.value ?? null
  }
}, { deep: true })

watch(exportFormat, () => {
  if (!payerSelectOptions.value.some(option =>
    option.value === payerReference.value,
  )) {
    payerReference.value = payerSelectOptions.value[0]?.value ?? null
  }
})

/*
 * Vybraná položka se v serverovém režimu zapamatuje jako celý objekt: jakmile
 * uživatel přepíše hledání, zmizí z nabídky, a bez uloženého objektu by spadl
 * limit částky na nulu a tlačítko by zšedlo bez vysvětlení.
 */
watch(selectedAllocationId, (id) => {
  pickedAllocation.value = id === null
    ? null
    : allocationPool.value.get(id) ?? pickedAllocation.value
  // Nová alokace = jiná měna a směr, takže dosavadní výsledky hledání
  // důkazů už nemusí platit. Znovu se ptáme prázdným dotazem.
  matchEvidenceSearchResults.value = []
  matchEvidenceSearchTruncated.value = false
  if (matchEvidenceRemote.value) void searchMatchEvidenceOptions('')
})

watch(selectedMatchEvidence, (key) => {
  if (key === null) {
    pickedMatchEvidence.value = null
    return
  }
  const found = matchEvidenceCandidates.value.find(
    item => evidenceKey(item) === key,
  )
  if (found) pickedMatchEvidence.value = found
})

watch(selectedSourceMatchId, () => {
  reversalEvidenceSearchResults.value = []
  reversalEvidenceSearchTruncated.value = false
  if (reversalEvidenceRemote.value) void searchReversalEvidenceOptions('')
})

watch(selectedReversalEvidence, (key) => {
  if (key === null) {
    pickedReversalEvidence.value = null
    return
  }
  const found = reversalEvidenceCandidates.value.find(
    item => evidenceKey(item) === key,
  )
  if (found) pickedReversalEvidence.value = found
})

watch([selectedAllocationId, selectedMatchEvidence], () => {
  const options = matchEvidenceCandidates.value
  if (!options.some(
    item => evidenceKey(item) === selectedMatchEvidence.value,
  )) {
    selectedMatchEvidence.value = options[0]
      ? evidenceKey(options[0])
      : null
    return
  }
  matchAmount.value = matchLimitMinor.value > 0
    ? minorInput(matchLimitMinor.value)
    : ''
})

watch([selectedSourceMatchId, selectedReversalEvidence], () => {
  const options = reversalEvidenceCandidates.value
  if (!options.some(
    item => evidenceKey(item) === selectedReversalEvidence.value,
  )) {
    selectedReversalEvidence.value = options[0]
      ? evidenceKey(options[0])
      : null
    return
  }
  reversalAmount.value = reversalLimitMinor.value > 0
    ? minorInput(reversalLimitMinor.value)
    : ''
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.payments.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.payments.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.payments.period') }}</span>
          <input
            v-model="period"
            type="month"
            min="2024-01"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
            @change="reload"
          >
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.payments.reload') }}
        </button>
        <!--
          Hlavní akce stránky. Když není co zhmotnit, nese s sebou i větu proč —
          dřív to vysvětlení viselo jen v prázdném stavu (`empty_blocked`), takže
          u neprázdného seznamu uživatel mačkal mrtvé tlačítko bez nápovědy.
        -->
        <div v-if="auth.canWrite('payroll.payments')" class="flex flex-col items-start gap-1.5">
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canMaterialize || materializing"
            :title="disabledTitle(!canMaterialize, materializeBlockedReason)"
            data-test="materialize"
            @click="materialize"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.coin" />
            </svg>
            {{ materializing ? t('payroll.payments.materializing') : t('payroll.payments.materialize') }}
          </button>
          <p v-if="materializeBlockedReason" :class="BTN_DISABLED_NOTE" data-test="materialize-blocked">
            {{ materializeBlockedReason }}
          </p>
        </div>
      </div>
    </header>

    <section
      v-if="runShortcutRequested"
      data-test="run-payment-shortcut"
      class="flex items-start gap-3 rounded-xl border border-payroll-200 bg-payroll-50/50 p-4 text-sm text-neutral-700"
    >
      <svg class="mt-0.5 h-5 w-5 shrink-0 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.coin" />
      </svg>
      <div>
        <p class="font-semibold text-neutral-900">
          {{ t(`payroll.payments.run_shortcut.${runShortcutState ?? 'loading'}`) }}
        </p>
        <p class="mt-1 leading-snug">
          {{ runShortcutState === 'ready'
            ? t('payroll.payments.run_shortcut.ready_hint', { count: selectedItems.length })
            : runShortcutState === 'empty'
              ? t('payroll.payments.run_shortcut.empty_hint')
              : t('payroll.payments.run_shortcut.loading_hint') }}
        </p>
      </div>
    </section>

    <nav class="flex gap-1 overflow-x-auto border-b border-neutral-200" :aria-label="t('payroll.payments.tabs_label')">
      <button
        v-for="tab in (['liabilities', 'batches', 'settlements'] as const)"
        :key="tab"
        type="button"
        class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-500 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
      >
        {{ t(`payroll.payments.tabs.${tab}`) }}
      </button>
    </nav>

    <div
      v-if="!auth.canWrite('payroll.payments')"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600"
    >
      {{ t('payroll.payments.readonly_hint') }}
    </div>

    <template v-if="activeTab === 'liabilities'">
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_liabilities') }}</p>
          <p class="mt-2 text-xl font-semibold text-neutral-900">{{ formatMoney(totals.amount) }}</p>
        </article>
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_batched') }}</p>
          <p class="mt-2 text-xl font-semibold text-payroll-700">{{ formatMoney(totals.allocated) }}</p>
        </article>
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_settled') }}</p>
          <p class="mt-2 text-xl font-semibold text-success-700">{{ formatMoney(totals.settled) }}</p>
        </article>
      </section>

      <section
        v-if="selectedItems.length > 0"
        class="rounded-xl border border-payroll-200 bg-payroll-50/40 p-5 shadow-sm"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.batch.new_title') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-600">
              <span data-test="batch-selection-summary">
                {{ t('payroll.payments.batch.selection_summary', {
                  count: selectedItems.length,
                  amount: formatMoney(
                    selectedTotalMinor,
                    selectionAnchor?.currency_code || 'CZK',
                  ),
                }) }}
              </span>
            </p>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="selectedIds = []">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('payroll.payments.batch.clear_selection') }}
          </button>
        </div>
        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto] lg:items-end">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.payments.batch.export_format') }}
            </span>
            <SearchableSelect
              v-model="exportFormat"
              :options="formatSelectOptions"
              :clearable="false"
              accent="payroll"
              :placeholder="t('payroll.payments.batch.select_format')"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.payments.batch.payer_account') }}
            </span>
            <SearchableSelect
              v-model="payerReference"
              :options="payerSelectOptions"
              :clearable="false"
              accent="payroll"
              :placeholder="t('payroll.payments.batch.select_payer')"
              :no-results-label="t('payroll.payments.batch.no_payer')"
            />
          </label>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canCreateBatch || creatingBatch"
            @click="createBatch"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.plus" />
            </svg>
            {{ creatingBatch
              ? t('payroll.payments.batch.creating')
              : t('payroll.payments.batch.create') }}
          </button>
        </div>
        <p
          v-if="payerSelectOptions.length === 0"
          class="mt-3 text-sm text-warning-700"
        >
          {{ t('payroll.payments.batch.no_payer_hint') }}
        </p>
      </section>

      <!-- Pořadí stavů: načítá se → selhalo → prázdno → data. -->
      <div v-if="loading" class="space-y-3">
        <div v-for="index in 4" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <EmptyState
        v-else-if="loadFailed"
        variant="failed"
        boxed
        data-test="load-failed"
        :message="t('payroll.payments.load_failed_hint')"
        @action="load"
      />
      <section v-else-if="items.length === 0" class="rounded-xl border border-dashed border-neutral-300 bg-surface px-5 py-12 text-center">
        <svg class="mx-auto h-10 w-10 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        <h2 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.payments.empty') }}</h2>
        <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-500">
          {{ materializableRevisions.length ? t('payroll.payments.empty_ready') : t('payroll.payments.empty_blocked') }}
        </p>
      </section>

      <template v-else>
        <section data-layout="desktop" class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
          <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
            <ColumnPicker class="hidden md:block" :ctrl="tbl" />
            <DensityToggle class="hidden md:block" :ctrl="tbl" />
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th v-if="auth.canWrite('payroll.payments')" class="w-12 px-4 py-3">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
                      :checked="selectedIds.length > 0"
                      :aria-label="t('payroll.payments.batch.select_all')"
                      @change="toggleAll"
                    >
                  </th>
                  <th v-if="tbl.isVisible('recipient')" class="px-4 py-3">{{ t('payroll.payments.recipient_label') }}</th>
                  <th v-if="tbl.isVisible('kind')" class="px-4 py-3">{{ t('payroll.payments.kind_label') }}</th>
                  <th v-if="tbl.isVisible('destination')" class="px-4 py-3">{{ t('payroll.payments.destination') }}</th>
                  <th v-if="tbl.isVisible('due_on')" class="px-4 py-3">{{ t('payroll.payments.due_on') }}</th>
                  <th v-if="tbl.isVisible('amount')" class="px-4 py-3 text-right">{{ t('payroll.payments.amount') }}</th>
                  <th v-if="tbl.isVisible('settled')" class="px-4 py-3 text-right">{{ t('payroll.payments.settled') }}</th>
                  <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.payments.status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in items" :key="item.id">
                  <td v-if="auth.canWrite('payroll.payments')" class="px-4 py-3">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500 disabled:cursor-not-allowed disabled:opacity-40"
                      :checked="isSelected(item.id)"
                      :disabled="!isSelected(item.id) && !isSelectable(item)"
                      :aria-label="t('payroll.payments.batch.select_employee', {
                        name: recipientName(item),
                      })"
                      @change="toggleSelection(item)"
                    >
                  </td>
                  <td v-if="tbl.isVisible('recipient')" class="px-4 py-3">
                    <div class="font-medium text-neutral-900">{{ recipientName(item) }}</div>
                    <div v-if="item.institution_code" class="mt-0.5 text-xs text-neutral-500">
                      {{ item.institution_code }}
                    </div>
                  </td>
                  <td v-if="tbl.isVisible('kind')" class="px-4 py-3 text-neutral-700">{{ kindLabel(item.liability_kind) }}</td>
                  <td v-if="tbl.isVisible('destination')" class="px-4 py-3 text-neutral-600">
                    <div>{{ t(`payroll.payments.recipient.${item.recipient_kind}`) }}</div>
                    <div v-if="item.payment_target_masked" class="mt-0.5 text-xs text-neutral-500">
                      {{ item.payment_target_masked }}
                    </div>
                    <span
                      v-if="item.recipient_kind === 'bank' && item.payment_target_status === 'ready'"
                      class="mt-1 inline-flex rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700"
                    >
                      {{ t('payroll.payments.target.ready') }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('due_on')" class="whitespace-nowrap px-4 py-3 text-neutral-600">{{ formatDate(item.due_on) }}</td>
                  <td v-if="tbl.isVisible('amount')" class="whitespace-nowrap px-4 py-3 text-right font-medium" :class="item.direction === 'incoming' ? 'text-success-700' : 'text-neutral-900'">
                    {{ formatMoney(signed(item, item.amount_minor), item.currency_code) }}
                  </td>
                  <td v-if="tbl.isVisible('settled')" class="whitespace-nowrap px-4 py-3 text-right text-neutral-600">{{ formatMoney(signed(item, item.settled_minor), item.currency_code) }}</td>
                  <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                      <span class="rounded-full px-2 py-1 text-xs font-medium" :class="stateClass(item.state)">{{ stateLabel(item.state) }}</span>
                      <span v-if="item.revision_kind === 'correction'" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">
                        {{ t('payroll.payments.correction') }}
                      </span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section data-layout="mobile" class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="flex min-w-0 items-start gap-3">
                <input
                  v-if="auth.canWrite('payroll.payments')"
                  type="checkbox"
                  class="mt-1 h-4 w-4 shrink-0 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500 disabled:cursor-not-allowed disabled:opacity-40"
                  :checked="isSelected(item.id)"
                  :disabled="!isSelected(item.id) && !isSelectable(item)"
                  :aria-label="t('payroll.payments.batch.select_employee', {
                    name: recipientName(item),
                  })"
                  @change="toggleSelection(item)"
                >
                <div class="min-w-0">
                  <h2 class="truncate font-semibold text-neutral-900">{{ recipientName(item) }}</h2>
                  <p class="mt-1 text-sm text-neutral-600">
                    {{ kindLabel(item.liability_kind) }}
                    <template v-if="item.institution_code"> · {{ item.institution_code }}</template>
                  </p>
                </div>
              </div>
              <div class="flex flex-wrap justify-end gap-1">
                <span class="rounded-full px-2 py-1 text-xs font-medium" :class="stateClass(item.state)">{{ stateLabel(item.state) }}</span>
                <span v-if="item.revision_kind === 'correction'" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">
                  {{ t('payroll.payments.correction') }}
                </span>
              </div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.destination') }}</dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ t(`payroll.payments.recipient.${item.recipient_kind}`) }}
                  <span v-if="item.payment_target_masked" class="block text-xs text-neutral-500">
                    {{ item.payment_target_masked }}
                  </span>
                  <span
                    v-if="item.recipient_kind === 'bank' && item.payment_target_status === 'ready'"
                    class="mt-1 inline-flex rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700"
                  >
                    {{ t('payroll.payments.target.ready') }}
                  </span>
                </dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.amount') }}</dt>
                <dd class="mt-0.5 font-semibold text-neutral-900">{{ formatMoney(signed(item, item.amount_minor), item.currency_code) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.settled') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatMoney(signed(item, item.settled_minor), item.currency_code) }}</dd>
              </div>
            </dl>
          </article>
        </section>

        <PaginationBar
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </template>
    </template>

    <template v-else-if="activeTab === 'batches'">
      <div v-if="loading" class="space-y-3">
        <div v-for="index in 3" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <section
        v-else-if="batches.length === 0"
        class="rounded-xl border border-dashed border-neutral-300 bg-surface px-5 py-12 text-center"
      >
        <svg class="mx-auto h-10 w-10 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        <h2 class="mt-3 font-semibold text-neutral-900">
          {{ t('payroll.payments.batch.empty') }}
        </h2>
        <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-500">
          {{ t('payroll.payments.batch.empty_hint') }}
        </p>
      </section>
      <template v-else>
        <section data-layout="batch-desktop" class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.date') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.format_label') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.items') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.total') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.exports') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="batch in batches" :key="batch.id" class="align-top">
                  <td class="whitespace-nowrap px-4 py-3 text-neutral-700">
                    {{ formatDate(batch.planned_payment_date) }}
                  </td>
                  <td class="px-4 py-3">
                    <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium uppercase text-payroll-700">
                      {{ batch.export_format }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right text-neutral-700">
                    {{ batch.declared_item_count }}
                  </td>
                  <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-neutral-900">
                    {{ formatMoney(batch.declared_total_minor, batch.currency_code) }}
                  </td>
                  <td class="px-4 py-3">
                    <div v-if="batch.exports.length" class="space-y-2">
                      <div v-for="file in batch.exports" :key="file.id" class="flex flex-wrap items-center gap-2">
                        <span class="text-neutral-700">
                          {{ t('payroll.payments.batch.revision', { revision: file.revision_no }) }}
                        </span>
                        <span class="text-xs text-neutral-500">
                          {{ formatFileSize(file.size_bytes) }} · {{ formatDateTime(file.created_at) }}
                        </span>
                        <button
                          v-if="auth.canWrite('payroll.payments')"
                          type="button"
                          :class="btnOutlineSm('neutral')"
                          :disabled="downloadingExportId !== null"
                          @click="downloadExport(file)"
                        >
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path :d="ICONS.download" />
                          </svg>
                          {{ downloadingExportId === file.id
                            ? t('payroll.payments.batch.downloading')
                            : t('payroll.payments.batch.download') }}
                        </button>
                      </div>
                    </div>
                    <span v-else class="text-neutral-500">
                      {{ batch.export_format === 'manual'
                        ? t('payroll.payments.batch.manual')
                        : t('payroll.payments.batch.no_export') }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right">
                    <button
                      v-if="batch.export_format !== 'manual' && auth.canWrite('payroll.payments')"
                      type="button"
                      :class="btnFilledSm('primary')"
                      :disabled="generatingBatchId !== null"
                      @click="generateExport(batch)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.plus" />
                      </svg>
                      {{ generatingBatchId === batch.id
                        ? t('payroll.payments.batch.generating')
                        : t('payroll.payments.batch.generate') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section data-layout="batch-mobile" class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="batch in batches" :key="batch.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-semibold text-neutral-900">
                  {{ formatMoney(batch.declared_total_minor, batch.currency_code) }}
                </h2>
                <p class="mt-1 text-sm text-neutral-500">
                  {{ formatDate(batch.planned_payment_date) }} ·
                  {{ t('payroll.payments.batch.item_count', { count: batch.declared_item_count }) }}
                </p>
              </div>
              <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium uppercase text-payroll-700">
                {{ batch.export_format }}
              </span>
            </div>
            <div v-if="batch.exports.length" class="mt-4 space-y-3">
              <div v-for="file in batch.exports" :key="file.id" class="rounded-lg bg-neutral-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p class="text-sm font-medium text-neutral-800">
                      {{ t('payroll.payments.batch.revision', { revision: file.revision_no }) }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-500">
                      {{ formatFileSize(file.size_bytes) }} · {{ formatDateTime(file.created_at) }}
                    </p>
                  </div>
                  <button
                    v-if="auth.canWrite('payroll.payments')"
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="downloadingExportId !== null"
                    @click="downloadExport(file)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.download" />
                    </svg>
                    {{ t('payroll.payments.batch.download') }}
                  </button>
                </div>
              </div>
            </div>
            <p v-else class="mt-4 text-sm text-neutral-500">
              {{ batch.export_format === 'manual'
                ? t('payroll.payments.batch.manual')
                : t('payroll.payments.batch.no_export') }}
            </p>
            <button
              v-if="batch.export_format !== 'manual' && auth.canWrite('payroll.payments')"
              type="button"
              class="mt-4"
              :class="btnFilled('primary')"
              :disabled="generatingBatchId !== null"
              @click="generateExport(batch)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.plus" />
              </svg>
              {{ generatingBatchId === batch.id
                ? t('payroll.payments.batch.generating')
                : t('payroll.payments.batch.generate') }}
            </button>
          </article>
        </section>
      </template>
    </template>

    <template v-else>
      <div v-if="loading" class="space-y-3">
        <div v-for="index in 3" :key="index" class="h-28 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <template v-else>
        <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
          <h2 class="font-semibold text-neutral-900">
            {{ t('payroll.payments.settlements.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-600">
            {{ t('payroll.payments.settlements.foundation') }}
          </p>
        </section>

        <section
          v-if="auth.canWrite('payroll.payments')"
          class="grid grid-cols-1 gap-4 xl:grid-cols-2"
        >
          <form
            class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm"
            @submit.prevent="matchPayment"
          >
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.settlements.new_match') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.payments.settlements.new_match_hint') }}
            </p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.allocation') }}
                </span>
                <SearchableSelect
                  v-model="selectedAllocationId"
                  :options="allocationSelectOptions"
                  accent="payroll"
                  :remote="allocationsTruncated"
                  :loading="allocationSearching"
                  :truncated="allocationsTruncated && allocationSearchTruncated"
                  :truncated-label="t('payroll.payments.settlements.options_truncated')"
                  :loading-label="t('common.loading')"
                  :placeholder="t('payroll.payments.settlements.select_allocation')"
                  :no-results-label="t('payroll.payments.settlements.no_allocations')"
                  @search="searchAllocationOptions"
                />
              </label>
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.evidence') }}
                </span>
                <SearchableSelect
                  v-model="selectedMatchEvidence"
                  :options="matchEvidenceSelectOptions"
                  accent="payroll"
                  :remote="matchEvidenceRemote"
                  :loading="matchEvidenceSearching"
                  :truncated="matchEvidenceRemote && matchEvidenceSearchTruncated"
                  :truncated-label="t('payroll.payments.settlements.options_truncated')"
                  :loading-label="t('common.loading')"
                  :placeholder="t('payroll.payments.settlements.select_evidence')"
                  :no-results-label="t('payroll.payments.settlements.no_evidence')"
                  @search="searchMatchEvidenceOptions"
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.match_amount') }}
                </span>
                <input
                  v-model="matchAmount"
                  type="text"
                  inputmode="decimal"
                  class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
                  :placeholder="t('payroll.payments.settlements.amount_placeholder')"
                >
              </label>
              <div class="flex items-end">
                <button
                  type="submit"
                  class="w-full sm:w-auto"
                  :class="btnFilled('success')"
                  :disabled="!canMatch || matching"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.check" />
                  </svg>
                  {{ matching
                    ? t('payroll.payments.settlements.matching')
                    : t('payroll.payments.settlements.match') }}
                </button>
              </div>
            </div>
            <p v-if="selectedAllocation && matchEvidenceCandidates.length === 0" class="mt-3 text-sm text-warning-700">
              {{ t('payroll.payments.settlements.no_compatible_evidence') }}
            </p>
          </form>

          <form
            class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm"
            @submit.prevent="reversePayment"
          >
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.settlements.new_reversal') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.payments.settlements.new_reversal_hint') }}
            </p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.source_match') }}
                </span>
                <SearchableSelect
                  v-model="selectedSourceMatchId"
                  :options="sourceMatchSelectOptions"
                  accent="payroll"
                  :placeholder="t('payroll.payments.settlements.select_source_match')"
                  :no-results-label="t('payroll.payments.settlements.no_reversible_matches')"
                />
              </label>
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.reversal_evidence') }}
                </span>
                <SearchableSelect
                  v-model="selectedReversalEvidence"
                  :options="reversalEvidenceSelectOptions"
                  accent="payroll"
                  :remote="reversalEvidenceRemote"
                  :loading="reversalEvidenceSearching"
                  :truncated="reversalEvidenceRemote && reversalEvidenceSearchTruncated"
                  :truncated-label="t('payroll.payments.settlements.options_truncated')"
                  :loading-label="t('common.loading')"
                  :placeholder="t('payroll.payments.settlements.select_reversal_evidence')"
                  :no-results-label="t('payroll.payments.settlements.no_reversal_evidence')"
                  @search="searchReversalEvidenceOptions"
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.reversal_amount') }}
                </span>
                <input
                  v-model="reversalAmount"
                  type="text"
                  inputmode="decimal"
                  class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
                  :placeholder="t('payroll.payments.settlements.amount_placeholder')"
                >
              </label>
              <div class="flex items-end">
                <button
                  type="submit"
                  class="w-full sm:w-auto"
                  :class="btnFilled('warning')"
                  :disabled="!canReverse || reversing"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.cycle" />
                  </svg>
                  {{ reversing
                    ? t('payroll.payments.settlements.reversing')
                    : t('payroll.payments.settlements.reverse') }}
                </button>
              </div>
            </div>
            <p v-if="selectedSourceMatch && reversalEvidenceCandidates.length === 0" class="mt-3 text-sm text-warning-700">
              {{ selectedSourceMatch.evidence_kind === 'cash'
                ? t('payroll.payments.settlements.cash_reversal_hint')
                : t('payroll.payments.settlements.no_reversal_evidence') }}
            </p>
          </form>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
          <h2 class="font-semibold text-neutral-900">
            {{ t('payroll.payments.settlements.history') }}
          </h2>
          <p v-if="paymentMatches.length === 0" class="mt-4 text-sm text-neutral-500">
            {{ t('payroll.payments.settlements.empty_history') }}
          </p>
          <div v-else class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <article
              v-for="event in paymentMatches"
              :key="event.id"
              class="rounded-lg border border-neutral-200 p-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <h3 class="truncate font-medium text-neutral-900">
                    {{ event.employee_name || t('payroll.payments.company') }}
                  </h3>
                  <p class="mt-1 text-sm text-neutral-500">
                    {{ kindLabel(event.liability_kind) }} ·
                    {{ formatDate(event.actual_payment_date) }}
                  </p>
                </div>
                <span
                  class="rounded-full px-2 py-1 text-xs font-medium"
                  :class="event.event_kind === 'matched'
                    ? 'bg-success-50 text-success-700'
                    : 'bg-warning-50 text-warning-700'"
                >
                  {{ t(`payroll.payments.settlements.event.${event.event_kind}`) }}
                </span>
              </div>
              <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt class="text-xs text-neutral-500">
                    {{ t('payroll.payments.amount') }}
                  </dt>
                  <dd class="mt-0.5 font-semibold text-neutral-900">
                    {{ formatMoney(event.amount_minor, event.evidence_currency_code) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-neutral-500">
                    {{ t('payroll.payments.settlements.evidence') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">
                    {{ t(`payroll.payments.recipient.${event.evidence_kind}`) }}
                  </dd>
                </div>
              </dl>
            </article>
          </div>
          <PaginationBar
            v-if="paymentMatches.length"
            class="mt-4"
            :page="matchPage"
            :per-page="matchPageSize"
            :total="matchTotal"
            @update:page="goToMatchPage"
          />
        </section>
      </template>
    </template>
  </div>
</template>

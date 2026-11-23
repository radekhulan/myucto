<script setup lang="ts">
/**
 * Hromadné akce nad výběrem ze seznamu (vydané a přijaté faktury, účetní deník).
 *
 * Postup je stejný jako na stránce Přeúčtovat: volba operace a parametrů, běh nanečisto,
 * kontrola výsledku po záložkách (s ručním vyřazením položek), ostrý běh a případné vrácení.
 * Které operace jsou dostupné a proč ne, rozhoduje server (`GET /bulk-actions`).
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  bulkActionsApi,
  BULK_ITEMS_PER_PAGE,
  type BulkItem,
  type BulkItemsPage,
  type BulkJob,
  type BulkOperation,
  type BulkSelection,
  type BulkStatus,
  type BulkTarget,
} from '@/api/bulkActions'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { apiErrorMessage } from '@/api/errors'
import { useBulkJobs } from '@/composables/useBulkJobs'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import BulkJobProgress from '@/components/accounting/BulkJobProgress.vue'
import BulkJobTabs from '@/components/accounting/BulkJobTabs.vue'
import DimensionPicker from '@/components/dimensions/DimensionPicker.vue'
import PostingRuleSelect from './PostingRuleSelect.vue'
import NoteParams from './NoteParams.vue'
import VariableSymbolParams from './VariableSymbolParams.vue'
import { showsReasonWhenApplied } from './noteSymbolForm'
import BulkItemFinder from './BulkItemFinder.vue'
import VatClassificationFields from './VatClassificationFields.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { buildCsv, downloadCsv, fetchAllPages } from '@/utils/bulkJobCsv'
import {
  BULK_CSV_COLUMNS,
  BULK_OPERATIONS,
  buildApplyRequest,
  buildDryRunRequest,
  bulkCsvRows,
  bulkReasonText,
  deleteConfirmMatches,
  emptyBulkForm,
  itemChanges,
  itemTargetFor,
  type BulkDimensionChoiceMode,
} from './bulkActionsForm'

const props = defineProps<{
  target: BulkTarget
  selection: BulkSelection
  selectionCount: number
  /** „Všech N podle filtru" jako druhá volba vedle ručního výběru. */
  filterSelection?: BulkSelection | null
  filterCount?: number
}>()

const emit = defineEmits<{ close: []; changed: [] }>()

const { t, locale } = useI18n()
const toast = useToast()
const dims = useDimensions()
const tt = (key: string, params?: Record<string, unknown>) => (params ? t(key, params) : t(key))

// ── Doklady, nebo jejich položky (agenda Položky dokladů u faktur) ─────────────
const itemTarget = computed(() => itemTargetFor(props.target))
const itemsMode = ref(false)
const effectiveTarget = computed<BulkTarget>(() => (itemsMode.value && itemTarget.value) || props.target)
const itemSelection = ref<BulkSelection | null>(null)
const itemCount = ref(0)

const {
  state, running, loading, starting, cancelling, error, isRunning,
  load, startJob, cancel, stopPolling,
  selected, activeTab, page, itemsPage, itemsLoading, itemsError, counts, excluded,
  tabs, selectedFinished, canExclude, applyCount, canApplySelected,
  selectJob, setTab, setPage, isIncluded, toggleItem, pageAllIncluded, togglePage,
} = useBulkJobs<BulkJob, BulkStatus, BulkItem>({
  api: {
    status: () => bulkActionsApi.status(effectiveTarget.value),
    job: bulkActionsApi.job,
    cancel: bulkActionsApi.cancel,
    items: (id, status, p) => bulkActionsApi.items(id, status, p),
  },
  itemKey: item => item.id,
  i18nPrefix: 'bulk_actions',
  autoSelectLatest: false,
})

// ── Výběr ──────────────────────────────────────────────────────────────────
const useFilter = ref(false)
const canSwitch = computed(() => !!props.filterSelection && 'ids' in props.selection && props.selectionCount > 0)
const activeSelection = computed<BulkSelection | null>(() => itemsMode.value ? itemSelection.value
  : useFilter.value && props.filterSelection ? props.filterSelection : props.selection)
const activeCount = computed(() => itemsMode.value ? itemCount.value
  : useFilter.value && props.filterSelection ? (props.filterCount ?? 0) : props.selectionCount)
const overLimit = computed(() => !!state.value && activeCount.value > state.value.limit)

// ── Formulář ───────────────────────────────────────────────────────────────
const form = reactive(emptyBulkForm())
const accounts = ref<ChartAccount[]>([])
const canRun = computed(() => !!state.value?.can_run)
const operations = computed(() => {
  const info = new Map((state.value?.operations ?? []).map(o => [o.key, o]))
  return BULK_OPERATIONS.filter(key => info.has(key)).map(key => info.get(key)!)
})
const isInvoiceTarget = computed(() => props.target !== 'journal_entry')
const dimensionTypes = computed(() => dims.types.value.filter(ty => ty.is_active))
const DIM_MODES: BulkDimensionChoiceMode[] = ['keep', 'set', 'fill', 'clear']

function resetDimensionChoices() {
  const next: typeof form.dimensions = {}
  for (const type of dimensionTypes.value) next[type.id] = form.dimensions[type.id] ?? { mode: 'keep', valueId: null }
  form.dimensions = next
}
watch(() => dimensionTypes.value.map(ty => ty.id).join(','), resetDimensionChoices)

watch(operations, ops => {
  if (form.operation && ops.some(o => o.key === form.operation && o.available)) return
  form.operation = ops.find(o => o.available)?.key ?? ''
})

const dryRunRequest = computed(() => activeSelection.value
  ? buildDryRunRequest(effectiveTarget.value, activeSelection.value, form)
  : null)
const vatDirection = computed(() => props.target === 'purchase_invoice' ? 'purchase' : 'sale')

function setItemsMode(on: boolean) {
  if (on === itemsMode.value || isRunning.value) return
  itemsMode.value = on
  // Stav, historie i výsledek patří k jinému cíli: načíst znovu od začátku.
  stopPolling()
  selected.value = null
  running.value = null
  state.value = null
  void load()
}

function startDryRun() {
  const body = dryRunRequest.value
  if (!body) return
  void startJob(() => bulkActionsApi.start(body))
}

// ── Výsledek ───────────────────────────────────────────────────────────────
const labels = computed(() => (itemsPage.value as BulkItemsPage | null)?.labels)
const selectedOperation = computed<BulkOperation>(() => selected.value?.operation ?? 'rebook_account')
const isDelete = computed(() => selectedOperation.value === 'delete')
const typedCount = ref('')
const deleteConfirmed = computed(() => deleteConfirmMatches(typedCount.value, applyCount.value))
watch(() => selected.value?.id, () => { typedCount.value = '' })

const applyDisabled = computed(() => loading.value || starting.value || isRunning.value || !canApplySelected.value
  || applyCount.value === 0 || (isDelete.value && !deleteConfirmed.value))

function changesOf(item: BulkItem) {
  return itemChanges(selectedOperation.value, item, labels.value, tt)
}

const showReason = computed(() => activeTab.value !== 'would_change' && activeTab.value !== 'applied')

const resultTitle = computed(() => {
  const kind = selected.value?.kind
  return t(kind === 'apply' ? 'bulk_actions.result_title_apply'
    : kind === 'rollback' ? 'bulk_actions.result_title_rollback' : 'bulk_actions.result_title_dry')
})

const confirmApply = ref(false)
const rollbackTarget = ref<BulkJob | null>(null)

function onApplyClick() {
  if (applyDisabled.value) return
  if (isDelete.value) runApply()
  else confirmApply.value = true
}

function runApply() {
  const job = selected.value
  confirmApply.value = false
  if (!job) return
  const body = buildApplyRequest(job.operation, job.id, excluded.value, applyCount.value)
  void startJob(() => bulkActionsApi.start(body))
}

function runRollback() {
  const job = rollbackTarget.value
  rollbackTarget.value = null
  if (!job) return
  void startJob(() => bulkActionsApi.rollback(job.id))
}

// Seznam pod dialogem se po ostrém běhu nebo vrácení musí načíst znovu (jednou za úlohu).
const notifiedJobs = new Set<number>()
watch(running, job => {
  if (!job || job.kind === 'dry_run' || job.status === 'queued' || job.status === 'running' || notifiedJobs.has(job.id)) return
  notifiedJobs.add(job.id)
  emit('changed')
})

// ── Export CSV ─────────────────────────────────────────────────────────────
const exporting = ref(false)
async function exportCsv() {
  const job = selected.value
  if (!job || exporting.value) return
  exporting.value = true
  try {
    let lastLabels = labels.value
    const items = await fetchAllPages(async (p, perPage) => {
      const res = await bulkActionsApi.items(job.id, null, p, perPage)
      lastLabels = res.labels ?? lastLabels
      return res
    })
    const header = BULK_CSV_COLUMNS.map(c => t('bulk_actions.' + c))
    downloadCsv(`hromadna-akce-${job.id}.csv`, buildCsv(header, bulkCsvRows(job.operation, items, lastLabels, tt)))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    exporting.value = false
  }
}

// ── Formátování ────────────────────────────────────────────────────────────
function fmtDateTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}
function operationLabel(op: BulkOperation): string {
  return t('bulk_actions.operation.' + op)
}

onMounted(() => {
  void load()
  void dims.load().then(resetDimensionChoices).catch(() => { /* bez dimenzí jen chybí řádky typů */ })
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
})
onUnmounted(stopPolling)
</script>

<template>
  <Modal :title="t('bulk_actions.title')" width-class="max-w-5xl" @close="emit('close')">
    <div class="space-y-4 text-sm" data-test="bulk-actions-dialog">
      <!-- Výběr -->
      <div v-if="itemTarget" class="flex flex-wrap items-center gap-2">
        <div class="inline-flex flex-wrap gap-1" role="radiogroup" :aria-label="t('bulk_actions.mode_switch_label')" data-test="bulk-mode-switch">
          <button type="button" role="radio" :aria-checked="!itemsMode" class="whitespace-nowrap"
            :class="!itemsMode ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-mode-documents"
            :disabled="isRunning" @click="setItemsMode(false)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t('bulk_actions.mode_documents') }}
          </button>
          <button type="button" role="radio" :aria-checked="itemsMode" class="whitespace-nowrap"
            :class="itemsMode ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-mode-items"
            :disabled="isRunning" @click="setItemsMode(true)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
            {{ t('bulk_actions.mode_items') }}
          </button>
        </div>
      </div>
      <BulkItemFinder v-if="itemsMode && itemTarget" v-model:selection="itemSelection" v-model:count="itemCount"
        :target="itemTarget" :disabled="isRunning" />
      <div v-else class="flex flex-wrap items-center gap-2">
        <span class="font-medium text-neutral-700">{{ t('bulk_actions.target.' + target) }}:</span>
        <template v-if="canSwitch">
          <div class="inline-flex flex-wrap gap-1" role="radiogroup" :aria-label="t('bulk_actions.scope_label')">
            <button type="button" role="radio" :aria-checked="!useFilter" class="whitespace-nowrap"
              :class="!useFilter ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-scope-selected"
              :disabled="isRunning" @click="useFilter = false">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('bulk_actions.scope_selected', { n: selectionCount }) }}
            </button>
            <button type="button" role="radio" :aria-checked="useFilter" class="whitespace-nowrap"
              :class="useFilter ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-scope-filter"
              :disabled="isRunning" @click="useFilter = true">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.funnel" /></svg>
              {{ t('bulk_actions.scope_filter', { n: filterCount ?? 0 }) }}
            </button>
          </div>
        </template>
        <span v-else data-test="bulk-selection-count">
          {{ 'ids' in selection ? t('bulk_actions.scope_selected', { n: selectionCount }) : t('bulk_actions.scope_filter', { n: selectionCount }) }}
        </span>
      </div>

      <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3" role="alert" data-test="bulk-error">{{ error }}</div>
      <div v-if="loading && !state" class="py-6 text-center text-neutral-400">{{ t('common.loading') }}…</div>

      <template v-else-if="state">
        <p v-if="!canRun" class="text-warning-600" data-test="bulk-cannot-run">{{ t('bulk_actions.cannot_run') }}</p>
        <p v-else-if="overLimit" class="text-danger-500" data-test="bulk-over-limit">{{ t('bulk_actions.over_limit', { n: activeCount, limit: state.limit }) }}</p>

        <!-- Operace a parametry -->
        <div v-if="canRun" class="border border-neutral-200 rounded-lg p-4 space-y-4" data-test="bulk-form">
          <p v-if="operations.length === 0" class="text-neutral-500">{{ t('bulk_actions.no_operations') }}</p>
          <fieldset v-else>
            <legend class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bulk_actions.operation_label') }}</legend>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <label v-for="op in operations" :key="op.key"
                class="flex items-start gap-2 rounded-md border p-2"
                :class="[op.available ? 'cursor-pointer border-neutral-200 hover:bg-neutral-50' : 'border-neutral-100 opacity-60',
                         form.operation === op.key ? 'border-primary-400 bg-primary-50/60' : '']"
                :data-test="`bulk-op-${op.key}`">
                <input v-model="form.operation" type="radio" :value="op.key" :disabled="!op.available || isRunning" class="mt-0.5 h-4 w-4" />
                <span class="min-w-0">
                  <span class="block font-medium" :class="op.key === 'delete' ? 'text-danger-600' : 'text-neutral-900'">{{ operationLabel(op.key) }}</span>
                  <span class="block text-xs text-neutral-500">{{ t('bulk_actions.operation_hint.' + op.key) }}</span>
                  <span v-if="!op.available && op.reason" class="block text-xs text-warning-700" data-test="bulk-op-reason">{{ t('bulk_actions.unavailable', { reason: op.reason }) }}</span>
                </span>
              </label>
            </div>
          </fieldset>

          <!-- Přeúčtovat -->
          <div v-if="form.operation === 'rebook_account'" class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-test="bulk-params-rebook">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-from">{{ itemsMode ? t('bulk_actions.from_account_optional') : t('bulk_actions.from_account') }}</label>
              <ChartAccountSelect v-model="form.rebook.from_account" :accounts="accounts" input-id="bulk-from" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-to">{{ t('bulk_actions.to_account') }}</label>
              <ChartAccountSelect v-model="form.rebook.to_account" :accounts="accounts" input-id="bulk-to" />
            </div>
            <div v-if="!itemsMode">
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-side">{{ t('bulk_actions.side_label') }}</label>
              <select id="bulk-side" v-model="form.rebook.side" class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface">
                <option value="both">{{ t('bulk_actions.side_both') }}</option>
                <option value="debit">{{ t('bulk_actions.side_debit') }}</option>
                <option value="credit">{{ t('bulk_actions.side_credit') }}</option>
              </select>
            </div>
            <div v-if="!itemsMode">
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-rebook-mode">{{ t('bulk_actions.mode_label') }}</label>
              <select id="bulk-rebook-mode" v-model="form.rebook.mode" class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface">
                <option value="rewrite">{{ t('bulk_actions.mode_rewrite') }}</option>
                <option value="rewrite_or_reverse">{{ t('bulk_actions.mode_rewrite_or_reverse') }}</option>
              </select>
            </div>
          </div>

          <!-- Dimenze -->
          <div v-else-if="form.operation === 'set_dimensions'" class="space-y-3" data-test="bulk-params-dimensions">
            <p v-if="dimensionTypes.length === 0" class="text-neutral-500">{{ t('bulk_actions.dims_none') }}</p>
            <div v-for="type in dimensionTypes" :key="type.id"
              class="grid grid-cols-1 sm:grid-cols-[10rem_11rem_1fr] gap-2 sm:items-center" data-test="bulk-dimension-row">
              <span class="font-medium text-neutral-700">{{ type.name }}</span>
              <select v-if="form.dimensions[type.id]" v-model="form.dimensions[type.id]!.mode" :aria-label="type.name"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface" data-test="bulk-dimension-mode">
                <option v-for="m in DIM_MODES" :key="m" :value="m">{{ t('bulk_actions.dim_mode.' + m) }}</option>
              </select>
              <DimensionPicker v-if="form.dimensions[type.id] && (form.dimensions[type.id]!.mode === 'set' || form.dimensions[type.id]!.mode === 'fill')"
                v-model="form.dimensions[type.id]!.valueId" :type-id="type.id" teleport :aria-label="type.name"
                :placeholder="t('bulk_actions.dim_value_required')" />
            </div>
            <fieldset v-if="isInvoiceTarget && !itemsMode" data-test="bulk-dimension-scope">
              <legend class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bulk_actions.scope_title') }}</legend>
              <label class="flex items-start gap-2 cursor-pointer">
                <input v-model="form.scope" type="radio" value="document" class="mt-0.5 h-4 w-4" />
                <span><span class="block">{{ t('bulk_actions.scope_document') }}</span><span class="block text-xs text-neutral-500">{{ t('bulk_actions.scope_document_hint') }}</span></span>
              </label>
              <label class="mt-1 flex items-start gap-2 cursor-pointer">
                <input v-model="form.scope" type="radio" value="items" class="mt-0.5 h-4 w-4" />
                <span><span class="block">{{ t('bulk_actions.scope_items') }}</span><span class="block text-xs text-neutral-500">{{ t('bulk_actions.scope_items_hint') }}</span></span>
              </label>
            </fieldset>
            <div v-else-if="!isInvoiceTarget" data-test="bulk-account-prefixes">
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-prefixes">{{ t('bulk_actions.account_prefixes') }}</label>
              <input id="bulk-prefixes" v-model="form.accountPrefixes" type="text" :placeholder="t('bulk_actions.account_prefixes_placeholder')"
                class="w-full sm:w-64 h-10 px-3 border border-neutral-300 rounded-md font-mono bg-surface" />
              <p class="mt-1 text-xs text-neutral-500">{{ t('bulk_actions.account_prefixes_hint') }}</p>
            </div>
          </div>

          <!-- Předkontace -->
          <div v-else-if="form.operation === 'set_posting_rule'" class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-test="bulk-params-rule">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-rule">{{ t('bulk_actions.rule_label') }}</label>
              <PostingRuleSelect v-model="form.ruleKey" input-id="bulk-rule" :empty-label="t('bulk_actions.rule_none')"
                :direction="target === 'invoice' ? 'sale' : target === 'purchase_invoice' ? 'purchase' : null" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-rule-mode">{{ t('bulk_actions.mode_label') }}</label>
              <select id="bulk-rule-mode" v-model="form.ruleMode" class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface">
                <option value="rewrite">{{ t('bulk_actions.mode_rewrite') }}</option>
                <option value="rewrite_or_reverse">{{ t('bulk_actions.mode_rewrite_or_reverse') }}</option>
              </select>
            </div>
          </div>

          <!-- Poznámka faktur -->
          <NoteParams v-else-if="form.operation === 'set_note'" v-model="form.note" :disabled="isRunning" />

          <!-- Variabilní symbol pohybů -->
          <VariableSymbolParams v-else-if="form.operation === 'set_variable_symbol'" v-model="form.symbol" :disabled="isRunning" />

          <!-- Členění DPH -->
          <VatClassificationFields v-else-if="form.operation === 'set_vat_classification'"
            v-model:code="form.vat.code" v-model:rate-id="form.vat.rateId" :direction="vatDirection" :with-rate="itemsMode" :disabled="isRunning" />

          <!-- Smazat -->
          <div v-else-if="form.operation === 'delete'" class="space-y-3" data-test="bulk-params-delete">
            <p class="bg-danger-50 border border-danger-500/40 text-danger-600 rounded-md p-3" role="alert">{{ t('bulk_actions.delete_warning') }}</p>
            <fieldset v-if="target === 'journal_entry'" data-test="bulk-journal-fallback">
              <legend class="block text-xs font-medium text-neutral-500 mb-1">{{ t('bulk_actions.journal_fallback_label') }}</legend>
              <div class="flex flex-wrap gap-x-5 gap-y-1">
                <label v-for="f in (['skip', 'reverse'] as const)" :key="f" class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
                  <input v-model="form.journalFallback" type="radio" :value="f" class="h-4 w-4" />
                  {{ t('bulk_actions.journal_fallback.' + f) }}
                </label>
              </div>
            </fieldset>
          </div>

          <div v-if="operations.length > 0" class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ dryRunRequest ? t('bulk_actions.action_dry_hint') : t('bulk_actions.form_incomplete') }}</p>
            <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" data-test="bulk-dry-run"
              :disabled="!dryRunRequest || overLimit || starting || isRunning" @click="startDryRun">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
              {{ t('bulk_actions.action_dry') }}
            </button>
          </div>
        </div>

        <!-- Průběh -->
        <BulkJobProgress v-if="running" :job="running" i18n-prefix="bulk_actions" :cancelling="cancelling" @cancel="cancel" />

        <!-- Výsledek vybraného běhu -->
        <div v-if="selected && selectedFinished" class="border border-neutral-200 rounded-lg p-4" data-test="bulk-result">
          <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
            <h3 class="font-medium text-neutral-900">{{ resultTitle }} · {{ operationLabel(selected.operation) }}</h3>
            <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="exporting" data-test="bulk-export" @click="exportCsv">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ exporting ? t('common.loading') : t('bulk_actions.export_csv') }}
            </button>
          </div>
          <p class="text-neutral-700 mb-3">
            {{ t(selected.kind === 'dry_run' ? 'bulk_actions.summary' : 'bulk_actions.summary_done', {
              changed: selected.kind === 'dry_run' ? (counts.would_change ?? selected.changed_count) : selected.changed_count,
              skipped: counts.skipped ?? selected.skipped_count,
              unchanged: counts.unchanged ?? 0,
            }) }}
            <span v-if="selected.kind === 'dry_run' && excluded.size > 0" class="ml-2 text-neutral-500" data-test="bulk-excluded">
              · {{ t('bulk_actions.excluded_manually', { n: excluded.size }) }}
            </span>
          </p>

          <BulkJobTabs :tabs="tabs" :active="activeTab" :counts="counts" i18n-prefix="bulk_actions" :label="resultTitle" @select="setTab" />

          <div v-if="itemsError" class="mt-3 text-danger-500">{{ itemsError }}</div>
          <div v-else-if="itemsLoading && !itemsPage" class="py-6 text-center text-neutral-400">{{ t('common.loading') }}…</div>
          <p v-else-if="itemsPage && itemsPage.items.length === 0" class="py-6 text-center text-neutral-400">{{ t('bulk_actions.no_items') }}</p>
          <template v-else-if="itemsPage">
            <div class="divide-y divide-neutral-100" :class="itemsLoading ? 'opacity-60' : ''">
              <label v-if="canExclude" class="flex items-center gap-2 py-2 text-xs text-neutral-500 cursor-pointer">
                <input type="checkbox" class="h-4 w-4" :checked="pageAllIncluded" data-test="bulk-include-page" @change="togglePage" />
                {{ t('bulk_actions.include_page') }}
              </label>
              <div v-for="item in itemsPage.items" :key="item.id" class="py-2 grid grid-cols-1 md:grid-cols-[auto_9rem_1fr_8rem] gap-x-3 gap-y-1"
                :class="canExclude && !isIncluded(item) ? 'opacity-50' : ''" data-test="bulk-item">
                <input v-if="canExclude" type="checkbox" class="h-4 w-4 mt-0.5 cursor-pointer" :checked="isIncluded(item)"
                  :aria-label="item.document_no || String(item.source_id)" data-test="bulk-item-include" @change="toggleItem(item)" />
                <span v-else class="hidden md:block w-4"></span>
                <div class="min-w-0">
                  <div class="font-mono text-xs">{{ item.document_no || '—' }}</div>
                  <div class="text-xs text-neutral-500">{{ formatDate(item.entry_date) }}</div>
                </div>
                <div class="min-w-0">
                  <div v-if="item.description" class="truncate text-neutral-700" :title="item.description">{{ item.description }}</div>
                  <div v-for="(c, i) in changesOf(item)" :key="i" class="text-xs" data-test="bulk-item-change">
                    <span class="text-neutral-500">{{ c.label }}:</span>
                    <span class="font-mono"> {{ c.from }} → {{ c.to }}</span>
                  </div>
                  <div v-if="changesOf(item).length === 0 && !showReason" class="text-xs text-neutral-400">{{ t('bulk_actions.no_change') }}</div>
                  <div v-if="showReason || showsReasonWhenApplied(item)" class="text-xs text-neutral-600" data-test="bulk-item-reason">{{ bulkReasonText(item, t) }}</div>
                </div>
                <div class="font-mono md:text-right whitespace-nowrap">{{ formatMoney(item.amount) }}</div>
              </div>
            </div>
            <PaginationBar class="mt-3" :page="page" :per-page="BULK_ITEMS_PER_PAGE" :total="itemsPage.total" @update:page="setPage" />
          </template>

          <!-- Provedení -->
          <div v-if="canRun && canApplySelected" class="mt-4 border-t border-neutral-100 pt-3 space-y-2" data-test="bulk-apply-box">
            <template v-if="isDelete">
              <p class="text-danger-600">{{ t('bulk_actions.delete_warning') }}</p>
              <label class="block text-neutral-700" for="bulk-delete-count">{{ t('bulk_actions.delete_confirm_label', { n: applyCount }) }}</label>
              <input id="bulk-delete-count" v-model="typedCount" type="text" inputmode="numeric" autocomplete="off"
                class="w-32 h-10 px-3 border border-neutral-300 rounded-md font-mono bg-surface" data-test="bulk-delete-confirm" />
            </template>
            <div class="flex flex-wrap justify-end gap-2">
              <button type="button" class="whitespace-nowrap" :class="isDelete ? btnFilled('danger') : btnFilled('primary')"
                :disabled="applyDisabled" data-test="bulk-apply" @click="onApplyClick">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="isDelete ? ICONS.trash : ICONS.check" /></svg>
                {{ isDelete ? t('bulk_actions.action_apply_delete', { n: applyCount }) : t('bulk_actions.action_apply') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Historie -->
        <div v-if="state.jobs.length > 0" class="border border-neutral-200 rounded-lg p-4" data-test="bulk-history">
          <h3 class="font-medium text-neutral-900 mb-2">{{ t('bulk_actions.history_title') }}</h3>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th class="py-2 pr-3">{{ t('bulk_actions.col_started') }}</th>
                  <th class="py-2 pr-3">{{ t('bulk_actions.col_operation') }}</th>
                  <th class="py-2 pr-3">{{ t('bulk_actions.col_kind') }}</th>
                  <th class="py-2 pr-3 text-right">{{ t('bulk_actions.col_changed') }}</th>
                  <th class="py-2 pr-3 text-right">{{ t('bulk_actions.col_skipped') }}</th>
                  <th class="py-2 pr-3 text-right">{{ t('bulk_actions.col_failed') }}</th>
                  <th class="py-2 pr-3">{{ t('bulk_actions.col_status') }}</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="j in state.jobs" :key="j.id" class="border-b border-neutral-50 last:border-0"
                  :class="selected?.id === j.id ? 'bg-primary-50/60' : ''" data-test="bulk-history-row">
                  <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDateTime(j.created_at) }}</td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ operationLabel(j.operation) }}</td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ t('bulk_actions.kind.' + j.kind) }}</td>
                  <td class="py-2 pr-3 text-right">{{ j.changed_count }}</td>
                  <td class="py-2 pr-3 text-right">{{ j.skipped_count }}</td>
                  <td class="py-2 pr-3 text-right" :class="j.failed_count > 0 ? 'text-danger-500 font-medium' : ''">{{ j.failed_count }}</td>
                  <td class="py-2 pr-3 whitespace-nowrap">
                    {{ t('bulk_actions.status.' + j.status) }}
                    <span v-if="j.rolled_back_by_job_id" class="ml-1 inline-flex items-center rounded-full bg-warning-50 border border-warning-200 px-2 py-0.5 text-[11px] font-medium text-warning-700">{{ t('bulk_actions.rolled_back_badge') }}</span>
                  </td>
                  <td class="py-2">
                    <div class="flex flex-wrap justify-end gap-1.5">
                      <button v-if="j.status !== 'queued' && j.status !== 'running'" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap"
                        data-test="bulk-history-result" @click="selectJob(j)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                        {{ t('bulk_actions.action_result') }}
                      </button>
                      <button v-if="canRun && j.kind === 'apply' && j.can_rollback" type="button" :class="btnOutlineSm('warning')" class="whitespace-nowrap"
                        :disabled="starting || isRunning" data-test="bulk-rollback" @click="rollbackTarget = j">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                        {{ t('bulk_actions.action_rollback') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('bulk_actions.close') }}
        </button>
      </div>
    </template>
  </Modal>

  <ConfirmDialog
    v-if="confirmApply && selected"
    :title="t('bulk_actions.confirm_apply_title')"
    :message="t('bulk_actions.confirm_apply', { operation: operationLabel(selected.operation), n: applyCount })"
    :confirm-label="t('bulk_actions.action_apply')"
    :busy="starting"
    @confirm="runApply"
    @cancel="confirmApply = false"
  />
  <ConfirmDialog
    v-if="rollbackTarget"
    :title="t('bulk_actions.confirm_rollback_title')"
    :message="t('bulk_actions.confirm_rollback', { n: rollbackTarget.changed_count })"
    :confirm-label="t('bulk_actions.action_rollback')"
    variant="warning"
    :busy="starting"
    @confirm="runRollback"
    @cancel="rollbackTarget = null"
  />
</template>

<script setup lang="ts">
/**
 * Hromadná práce s nespárovanými a nezaúčtovanými bankovními pohyby podle filtru.
 *
 * Zaúčtování na protiúčet je pro pohyby, které nemají s čím se spárovat (výplaty mezd),
 * párování pro pohyby patřící k jednomu dokladu (platby z karetního terminálu k měsíční
 * tržbě). Kostra úloh je stejná jako u přeúčtování: nanečisto, provedení přesně podle
 * běhu nanečisto bez ručně vyřazených pohybů, vrácení. O tom, kdo smí spouštět, rozhoduje
 * server polem `can_run` (párování navíc `can_match`).
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  bankBulkApi,
  BANK_BULK_PER_PAGE,
  type BankBulkAction,
  type BankBulkCount,
  type BankBulkDocumentType,
  type BankBulkFilter,
  type BankBulkItem,
  type BankBulkJob,
  type BankBulkStatus,
} from '@/api/bankBulk'
import { accountingApi, type ChartAccount, type LinkCandidate } from '@/api/accounting'
import { invoicesApi } from '@/api/invoices'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useBulkJobs } from '@/composables/useBulkJobs'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import DocumentLinkPicker from '@/components/accounting/DocumentLinkPicker.vue'
import BulkJobProgress from '@/components/accounting/BulkJobProgress.vue'
import BulkJobTabs from '@/components/accounting/BulkJobTabs.vue'
import { ICONS, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { buildCsv, downloadCsv, fetchAllPages } from '@/utils/bulkJobCsv'
import {
  BANK_BULK_CSV_COLUMNS,
  bankBulkAfterText,
  bankBulkCsvRows,
  bankBulkReasonText,
  buildBankBulkFilter,
  isBankBulkFormValid,
  type BankBulkForm,
} from '@/utils/bankBulk'

const emit = defineEmits<{ (e: 'changed'): void }>()

const { t, locale } = useI18n()
const toast = useToast()
const P = 'accounting.bank_bulk'

const accounts = ref<ChartAccount[]>([])

const {
  state, running, loading, starting, cancelling, error, isRunning,
  load, startJob, cancel, stopPolling,
  selected, activeTab, page, itemsPage, itemsLoading, itemsError, counts, excluded,
  tabs, selectedFinished, canExclude, applyCount, canApplySelected,
  selectJob, setTab, setPage, isIncluded, toggleItem, pageAllIncluded, togglePage,
} = useBulkJobs<BankBulkJob, BankBulkStatus, BankBulkItem>({
  api: bankBulkApi,
  itemKey: item => item.source_id,
  i18nPrefix: P,
})

const year = new Date().getFullYear()
const form = reactive<BankBulkForm>({
  action: 'post',
  date_from: `${year}-01-01`,
  date_to: `${year}-12-31`,
  bank_account: '',
  direction: 'out',
  text: '',
  counterparty_account: '',
  variable_symbol: '',
  amount_min: '',
  amount_max: '',
  counter_account: '',
  description: '',
  document_type: 'purchase_invoice',
  document_id: null,
})

const canRun = computed(() => !!state.value?.can_run)
const canMatch = computed(() => !!state.value?.can_match)
const formValid = computed(() => isBankBulkFormValid(form))
const isMatch = computed(() => form.action === 'match')
/** U párování se směr řídí dokladem: úhrada vydané faktury je příjem, přijaté výdej. */
const shownDirection = computed(() =>
  isMatch.value ? (form.document_type === 'invoice' ? 'in' : 'out') : form.direction)

// ── Vybraný doklad (párování) ──────────────────────────────────────────────
interface PickedDocument {
  type: BankBulkDocumentType
  id: number
  number: string
  partner: string | null
  amount: number | null
  currency: string
  remaining: number | null
}
const pickedDoc = ref<PickedDocument | null>(null)
let documentSeq = 0

async function pickDocument(c: LinkCandidate) {
  if (c.doc_type !== 'invoice' && c.doc_type !== 'purchase_invoice') return
  const picked: PickedDocument = {
    type: c.doc_type, id: c.doc_id, number: c.label, partner: c.sublabel,
    amount: c.amount, currency: c.currency || 'CZK', remaining: null,
  }
  pickedDoc.value = picked
  form.document_id = c.doc_id
  // Zbytek k úhradě je informace navíc: bez práva číst doklady se prostě neukáže.
  const seq = ++documentSeq
  try {
    let remaining: number | null = null
    if (c.doc_type === 'invoice') {
      const inv = await invoicesApi.get(c.doc_id)
      remaining = inv.amount_to_pay - (inv.paid_total ?? 0)
    } else {
      const pi = await purchaseInvoicesApi.get(c.doc_id)
      remaining = pi.remaining_amount ?? (pi.paid_amount !== undefined ? pi.amount_to_pay - pi.paid_amount : null)
    }
    if (seq === documentSeq && pickedDoc.value?.id === c.doc_id) pickedDoc.value = { ...picked, remaining }
  } catch {
    // zbytek k úhradě zůstane neznámý
  }
}

function clearDocument() {
  documentSeq++
  pickedDoc.value = null
  form.document_id = null
}

watch(() => form.document_type, () => {
  if (pickedDoc.value && pickedDoc.value.type !== form.document_type) clearDocument()
})

// ── Živý počet ─────────────────────────────────────────────────────────────
const count = ref<BankBulkCount | null>(null)
const countLoading = ref(false)
const countError = ref('')
let countTimer: ReturnType<typeof setTimeout> | null = null
let countSeq = 0

const overLimit = computed(() => !!count.value && count.value.matching > count.value.action_limit)

function scheduleCount() {
  if (countTimer !== null) clearTimeout(countTimer)
  countTimer = null
  countError.value = ''
  if (!formValid.value) {
    countSeq++
    count.value = null
    countLoading.value = false
    return
  }
  countLoading.value = true
  const seq = ++countSeq
  countTimer = setTimeout(async () => {
    countTimer = null
    try {
      const res = await bankBulkApi.count(buildBankBulkFilter(form))
      if (seq !== countSeq) return
      count.value = res
    } catch (e) {
      if (seq !== countSeq) return
      count.value = null
      countError.value = apiErrorMessage(e)
    } finally {
      if (seq === countSeq) countLoading.value = false
    }
  }, 400)
}

watch(
  () => [form.action, form.date_from, form.date_to, form.bank_account, form.direction, form.text,
    form.counterparty_account, form.variable_symbol, form.amount_min, form.amount_max,
    form.counter_account, form.document_type, form.document_id],
  scheduleCount,
)

function startDryRun() {
  void startJob(() => bankBulkApi.start({ ...buildBankBulkFilter(form), dry_run: true }))
}

// ── Potvrzení provedení a vrácení ──────────────────────────────────────────
const confirmApply = ref(false)
const rollbackTarget = ref<BankBulkJob | null>(null)

function actionKindLabel(action: BankBulkAction): string {
  return t(`accounting.bank_bulk.action_kind.${action}`)
}

function documentLabel(params: BankBulkFilter): string {
  if (!params.document_id) return '—'
  if (params.document_no) return params.document_no
  return pickedDoc.value && pickedDoc.value.id === params.document_id ? pickedDoc.value.number : `#${params.document_id}`
}

function targetLabel(params: BankBulkFilter): string {
  return params.action === 'match' ? documentLabel(params) : (params.counter_account ?? '—')
}

const applyLabel = computed(() =>
  t(selected.value?.params.action === 'match' ? `accounting.bank_bulk.action_apply_match` : `accounting.bank_bulk.action_apply_post`))

const applyMessage = computed(() => {
  const job = selected.value
  if (!job) return ''
  return job.params.action === 'match'
    ? t(`accounting.bank_bulk.confirm_apply_match`, { n: applyCount.value, document: documentLabel(job.params) })
    : t(`accounting.bank_bulk.confirm_apply_post`, { n: applyCount.value, account: job.params.counter_account ?? '—' })
})

function runApply() {
  const job = selected.value
  confirmApply.value = false
  if (!job) return
  void startJob(() => bankBulkApi.start({
    dry_run: false, dry_run_job_id: job.id, excluded_ids: [...excluded.value],
  }))
}

function runRollback() {
  const job = rollbackTarget.value
  rollbackTarget.value = null
  if (!job) return
  void startJob(() => bankBulkApi.rollback(job.id))
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'apply', label: applyLabel.value, icon: 'check',
    tier: 'primary', variant: 'primary',
    show: canRun.value,
    disabled: loading.value || starting.value || isRunning.value || !canApplySelected.value || applyCount.value === 0,
    disabledReason: !canApplySelected.value ? t(`accounting.bank_bulk.action_apply_reason`) : undefined,
    loading: starting.value,
    title: t(`accounting.bank_bulk.action_apply_hint`),
    run: () => { confirmApply.value = true },
  },
  {
    key: 'dry', label: t(`accounting.bank_bulk.action_dry`), icon: 'search',
    tier: 'secondary', variant: 'neutral',
    show: canRun.value,
    disabled: loading.value || starting.value || isRunning.value || !formValid.value || overLimit.value
      || (isMatch.value && !canMatch.value),
    title: t(`accounting.bank_bulk.action_dry_hint`),
    run: startDryRun,
  },
])

// Doběhlé provedení nebo vrácení mění počty čekajících pohybů na stránce kolem.
watch(running, (j, prev) => {
  if (!j || !prev || j.id !== prev.id || j.kind === 'dry_run') return
  const wasActive = prev.status === 'queued' || prev.status === 'running'
  if (wasActive && j.status !== 'queued' && j.status !== 'running') emit('changed')
})

// ── Export CSV ─────────────────────────────────────────────────────────────
const exporting = ref(false)

async function exportCsv() {
  const job = selected.value
  if (!job || exporting.value) return
  exporting.value = true
  try {
    const items = await fetchAllPages((p, perPage) => bankBulkApi.items(job.id, null, p, perPage))
    const header = BANK_BULK_CSV_COLUMNS.map(c => t(`accounting.bank_bulk.${c}`))
    downloadCsv(`bankovni-pohyby-${job.id}.csv`, buildCsv(header, bankBulkCsvRows(items, key => t(key))))
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

function statusLabel(status: BankBulkJob['status']): string {
  return t(`accounting.bank_bulk.status.${status}`)
}

function kindLabel(kind: BankBulkJob['kind']): string {
  return t(`accounting.bank_bulk.kind.${kind}`)
}

function accountLabel(a: { account_number: string; bank_code: string | null; label: string | null }): string {
  const number = a.bank_code ? `${a.account_number}/${a.bank_code}` : a.account_number
  return a.label ? `${a.label} (${number})` : number
}

function afterText(item: BankBulkItem): string {
  return bankBulkAfterText(item, key => t(key)) || '—'
}

function reasonText(item: BankBulkItem): string {
  return bankBulkReasonText(item, key => t(key))
}

const showReason = computed(() => activeTab.value !== 'would_change' && activeTab.value !== 'applied')

const resultTitle = computed(() => {
  const kind = selected.value?.kind
  return t(kind === 'apply' ? `accounting.bank_bulk.result_title_apply`
    : kind === 'rollback' ? `accounting.bank_bulk.result_title_rollback`
      : `accounting.bank_bulk.result_title_dry`)
})

onMounted(() => {
  void load()
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
})
onUnmounted(() => {
  stopPolling()
  if (countTimer !== null) clearTimeout(countTimer)
})
</script>

<template>
  <section id="bank-bulk" class="scroll-mt-4" data-test="bank-bulk">
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
      <h2 class="font-medium text-neutral-900 mb-1">{{ t(`accounting.bank_bulk.title`) }}</h2>
      <p class="text-sm text-neutral-600 mb-3">{{ t(`accounting.bank_bulk.intro`) }}</p>

      <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-3">
        {{ error }}
      </div>

      <div v-if="loading && !state" class="py-6 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>

      <template v-else>
        <ActionBar :actions="actions" class="mb-4" />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm" data-test="bank-bulk-form">
          <div class="sm:col-span-2">
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t(`accounting.bank_bulk.action_label`) }}</span>
            <div class="flex flex-wrap gap-x-5 gap-y-1">
              <label class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
                <input v-model="form.action" type="radio" value="post" :disabled="!canRun" class="h-4 w-4" />
                {{ t(`accounting.bank_bulk.action_post`) }}
              </label>
              <label v-if="canMatch" class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
                <input v-model="form.action" type="radio" value="match" :disabled="!canRun" class="h-4 w-4" data-test="bank-bulk-action-match" />
                {{ t(`accounting.bank_bulk.action_match`) }}
              </label>
            </div>
          </div>

          <div>
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t(`accounting.bank_bulk.period`) }}</span>
            <div class="flex items-center gap-2">
              <DateInput v-model="form.date_from" required :disabled="!canRun" :aria-label="t(`accounting.bank_bulk.date_from`)"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
              <span class="text-neutral-400">-</span>
              <DateInput v-model="form.date_to" required :disabled="!canRun" :aria-label="t(`accounting.bank_bulk.date_to`)"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-account">{{ t(`accounting.bank_bulk.bank_account`) }}</label>
            <select id="bank-bulk-account" v-model="form.bank_account" :disabled="!canRun" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t(`accounting.bank_bulk.bank_account_any`) }}</option>
              <option v-for="a in state?.accounts ?? []" :key="a.account_number + (a.bank_code ?? '')" :value="a.account_number">{{ accountLabel(a) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-direction">{{ t(`accounting.bank_bulk.direction`) }}</label>
            <select v-if="!isMatch" id="bank-bulk-direction" v-model="form.direction" :disabled="!canRun" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="out">{{ t(`accounting.bank_bulk.direction_out`) }}</option>
              <option value="in">{{ t(`accounting.bank_bulk.direction_in`) }}</option>
              <option value="both">{{ t(`accounting.bank_bulk.direction_both`) }}</option>
            </select>
            <select v-else id="bank-bulk-direction" :value="shownDirection" disabled :title="t(`accounting.bank_bulk.direction_by_document`)"
              class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="out">{{ t(`accounting.bank_bulk.direction_out`) }}</option>
              <option value="in">{{ t(`accounting.bank_bulk.direction_in`) }}</option>
            </select>
            <p v-if="isMatch" class="mt-1 text-xs text-neutral-500">{{ t(`accounting.bank_bulk.direction_by_document`) }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-text">{{ t(`accounting.bank_bulk.text`) }}</label>
            <input id="bank-bulk-text" v-model="form.text" type="text" :disabled="!canRun" :placeholder="t(`accounting.bank_bulk.text_placeholder`)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-counterparty">{{ t(`accounting.bank_bulk.counterparty_account`) }}</label>
            <input id="bank-bulk-counterparty" v-model="form.counterparty_account" type="text" :disabled="!canRun"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-vs">{{ t(`accounting.bank_bulk.variable_symbol`) }}</label>
            <input id="bank-bulk-vs" v-model="form.variable_symbol" type="text" inputmode="numeric" :disabled="!canRun"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
          </div>
          <div>
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t(`accounting.bank_bulk.amount`) }}</span>
            <div class="flex items-center gap-2">
              <input v-model="form.amount_min" type="text" inputmode="decimal" :disabled="!canRun" :aria-label="t(`accounting.bank_bulk.amount_min`)"
                :placeholder="t(`accounting.bank_bulk.amount_min`)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
              <span class="text-neutral-400">-</span>
              <input v-model="form.amount_max" type="text" inputmode="decimal" :disabled="!canRun" :aria-label="t(`accounting.bank_bulk.amount_max`)"
                :placeholder="t(`accounting.bank_bulk.amount_max`)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
            </div>
          </div>

          <!-- Zaúčtování na protiúčet -->
          <template v-if="!isMatch">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-counter">{{ t(`accounting.bank_bulk.counter_account`) }}</label>
              <ChartAccountSelect v-model="form.counter_account" :accounts="accounts" input-id="bank-bulk-counter" :disabled="!canRun" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-description">{{ t(`accounting.bank_bulk.description`) }}</label>
              <input id="bank-bulk-description" v-model="form.description" type="text" maxlength="255" :disabled="!canRun"
                :placeholder="t(`accounting.bank_bulk.description_placeholder`)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
            <p class="sm:col-span-2 text-xs text-neutral-500">{{ t(`accounting.bank_bulk.post_example`) }}</p>
          </template>

          <!-- Spárování s dokladem -->
          <template v-else>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1" for="bank-bulk-doctype">{{ t(`accounting.bank_bulk.document_type`) }}</label>
              <select id="bank-bulk-doctype" v-model="form.document_type" :disabled="!canRun" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="invoice">{{ t(`accounting.bank_bulk.document_invoice`) }}</option>
                <option value="purchase_invoice">{{ t(`accounting.bank_bulk.document_purchase_invoice`) }}</option>
              </select>
            </div>
            <div>
              <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t(`accounting.bank_bulk.document_search`) }}</span>
              <div v-if="pickedDoc" class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2" data-test="bank-bulk-document">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                  <span class="font-medium">{{ pickedDoc.number }}</span>
                  <button type="button" class="cursor-pointer text-xs text-primary-600 hover:underline whitespace-nowrap" :disabled="!canRun" @click="clearDocument">
                    {{ t(`accounting.bank_bulk.document_change`) }}
                  </button>
                </div>
                <div v-if="pickedDoc.partner" class="text-xs text-neutral-600 truncate">{{ pickedDoc.partner }}</div>
                <div class="text-xs text-neutral-600">
                  <span class="font-mono">{{ formatMoney(pickedDoc.amount ?? 0, pickedDoc.currency) }}</span>
                  <span v-if="pickedDoc.remaining !== null" class="ml-2">· {{ t(`accounting.bank_bulk.document_remaining`, { amount: formatMoney(pickedDoc.remaining, pickedDoc.currency) }) }}</span>
                </div>
              </div>
              <DocumentLinkPicker v-else :key="form.document_type" :types="[form.document_type]" :disabled="!canRun" @select="pickDocument" />
            </div>
          </template>
        </div>

        <div class="mt-4 text-sm" data-test="bank-bulk-count">
          <p v-if="!formValid" class="text-neutral-500">{{ t(isMatch ? `accounting.bank_bulk.form_incomplete_match` : `accounting.bank_bulk.form_incomplete_post`) }}</p>
          <p v-else-if="countError" class="text-danger-500">{{ countError }}</p>
          <p v-else-if="countLoading && !count" class="text-neutral-400">{{ t('common.loading') }}…</p>
          <template v-else-if="count">
            <p class="font-medium" :class="count.matching > 0 ? 'text-neutral-900' : 'text-neutral-500'">
              {{ t(`accounting.bank_bulk.matching`, {
                n: count.matching, total: formatMoney(count.total_amount),
                income: formatMoney(count.income), expense: formatMoney(count.expense),
              }) }}
            </p>
            <p v-if="overLimit" class="mt-1 text-danger-500" data-test="bank-bulk-over-limit">{{ t(`accounting.bank_bulk.over_limit`, { limit: count.action_limit }) }}</p>
          </template>
        </div>
      </template>
    </div>

    <!-- Průběh -->
    <BulkJobProgress v-if="running" :job="running" :i18n-prefix="P" :cancelling="cancelling" @cancel="cancel" />

    <!-- Výsledek vybraného běhu -->
    <div v-if="selected && selectedFinished" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4" data-test="bank-bulk-result">
      <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
        <h2 class="font-medium text-neutral-900">{{ resultTitle }}</h2>
        <div class="flex flex-wrap items-center gap-3">
          <span class="text-xs text-neutral-500">
            {{ actionKindLabel(selected.params.action) }} · <span class="font-mono">{{ targetLabel(selected.params) }}</span>
            · {{ formatDate(selected.params.date_from) }} - {{ formatDate(selected.params.date_to) }}
          </span>
          <button type="button" :class="btnOutline('neutral')" :disabled="exporting" data-test="bank-bulk-export" @click="exportCsv">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ exporting ? t('common.loading') : t(`accounting.bank_bulk.export_csv`) }}
          </button>
        </div>
      </div>
      <p class="text-sm text-neutral-700 mb-3">
        {{ t(selected.kind === 'dry_run' ? `accounting.bank_bulk.summary` : `accounting.bank_bulk.summary_done`, {
          changed: selected.kind === 'dry_run' ? (counts.would_change ?? selected.changed_count) : selected.changed_count,
          skipped: counts.skipped ?? selected.skipped_count,
          unchanged: counts.unchanged ?? 0,
        }) }}
        <span v-if="canExclude || (selected.kind === 'dry_run' && excluded.size > 0)" class="ml-2 text-neutral-500" data-test="bank-bulk-excluded">
          · {{ t(`accounting.bank_bulk.excluded_manually`, { n: excluded.size }) }}
        </span>
      </p>

      <BulkJobTabs :tabs="tabs" :active="activeTab" :counts="counts" :i18n-prefix="P" :label="resultTitle" @select="setTab" />

      <div v-if="itemsError" class="mt-3 text-sm text-danger-500">{{ itemsError }}</div>
      <div v-else-if="itemsLoading && !itemsPage" class="py-6 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
      <p v-else-if="itemsPage && itemsPage.items.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t(`accounting.bank_bulk.no_items`) }}</p>
      <template v-else-if="itemsPage">
        <!-- Desktop -->
        <div class="hidden md:block overflow-x-auto" :class="itemsLoading ? 'opacity-60' : ''">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                <th v-if="canExclude" class="py-2 pr-2 w-6">
                  <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="pageAllIncluded"
                    :aria-label="t(`accounting.bank_bulk.include_page`)" @change="togglePage" />
                </th>
                <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_vs`) }}</th>
                <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_date`) }}</th>
                <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_description`) }}</th>
                <th class="py-2 pr-3 text-right">{{ t(`accounting.bank_bulk.col_amount`) }}</th>
                <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_after`) }}</th>
                <th v-if="showReason" class="py-2">{{ t(`accounting.bank_bulk.col_reason`) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in itemsPage.items" :key="item.id" class="border-b border-neutral-50 last:border-0 align-top"
                :class="canExclude && !isIncluded(item) ? 'opacity-50' : ''">
                <td v-if="canExclude" class="py-2 pr-2">
                  <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="isIncluded(item)"
                    :aria-label="item.description || String(item.source_id)" @change="toggleItem(item)" />
                </td>
                <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ item.document_no || '—' }}</td>
                <td class="py-2 pr-3 whitespace-nowrap">{{ formatDate(item.entry_date) }}</td>
                <td class="py-2 pr-3 max-w-72 truncate" :title="item.description || undefined">{{ item.description || '—' }}</td>
                <td class="py-2 pr-3 text-right font-mono whitespace-nowrap" :class="item.amount < 0 ? 'text-danger-500' : ''">{{ formatMoney(item.amount) }}</td>
                <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ afterText(item) }}</td>
                <td v-if="showReason" class="py-2 text-neutral-600">{{ reasonText(item) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobil -->
        <div class="md:hidden divide-y divide-neutral-100" :class="itemsLoading ? 'opacity-60' : ''">
          <div v-for="item in itemsPage.items" :key="`m-${item.id}`" class="py-3 space-y-1 text-sm"
            :class="canExclude && !isIncluded(item) ? 'opacity-50' : ''">
            <div class="flex items-baseline justify-between gap-2">
              <label class="flex items-baseline gap-2 min-w-0">
                <input v-if="canExclude" type="checkbox" class="h-4 w-4 cursor-pointer self-center" :checked="isIncluded(item)"
                  :aria-label="item.description || String(item.source_id)" @change="toggleItem(item)" />
                <span class="font-mono text-xs text-neutral-600">{{ item.document_no || '—' }}</span>
                <span class="text-xs text-neutral-500">{{ formatDate(item.entry_date) }}</span>
              </label>
              <span class="font-mono font-semibold whitespace-nowrap" :class="item.amount < 0 ? 'text-danger-500' : ''">{{ formatMoney(item.amount) }}</span>
            </div>
            <div v-if="item.description" class="text-neutral-600 truncate">{{ item.description }}</div>
            <div class="font-mono text-xs">{{ afterText(item) }}</div>
            <div v-if="showReason" class="text-xs text-neutral-600">{{ reasonText(item) }}</div>
          </div>
        </div>

        <PaginationBar class="mt-3" :page="page" :per-page="BANK_BULK_PER_PAGE" :total="itemsPage.total" @update:page="setPage" />
      </template>
    </div>

    <!-- Historie -->
    <div v-if="state && state.jobs.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4" data-test="bank-bulk-history">
      <h2 class="font-medium text-neutral-900 mb-3">{{ t(`accounting.bank_bulk.history_title`) }}</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
              <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_started`) }}</th>
              <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_kind`) }}</th>
              <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_action`) }}</th>
              <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_target`) }}</th>
              <th class="py-2 pr-3 text-right">{{ t(`accounting.bank_bulk.col_changed`) }}</th>
              <th class="py-2 pr-3 text-right">{{ t(`accounting.bank_bulk.col_skipped`) }}</th>
              <th class="py-2 pr-3 text-right">{{ t(`accounting.bank_bulk.col_failed`) }}</th>
              <th class="py-2 pr-3">{{ t(`accounting.bank_bulk.col_status`) }}</th>
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="j in state.jobs" :key="j.id" class="border-b border-neutral-50 last:border-0"
              :class="selected?.id === j.id ? 'bg-primary-50/60' : ''">
              <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDateTime(j.created_at) }}</td>
              <td class="py-2 pr-3 whitespace-nowrap">{{ kindLabel(j.kind) }}</td>
              <td class="py-2 pr-3 whitespace-nowrap">{{ actionKindLabel(j.params.action) }}</td>
              <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ targetLabel(j.params) }}</td>
              <td class="py-2 pr-3 text-right">{{ j.changed_count }}</td>
              <td class="py-2 pr-3 text-right">{{ j.skipped_count }}</td>
              <td class="py-2 pr-3 text-right" :class="j.failed_count > 0 ? 'text-danger-500 font-medium' : ''">{{ j.failed_count }}</td>
              <td class="py-2 pr-3 whitespace-nowrap">
                {{ statusLabel(j.status) }}
                <span v-if="j.rolled_back_by_job_id" class="ml-1 inline-flex items-center rounded-full bg-warning-50 border border-warning-200 px-2 py-0.5 text-[11px] font-medium text-warning-700"
                  data-test="bank-bulk-rolled-back">{{ t(`accounting.bank_bulk.rolled_back_badge`) }}</span>
              </td>
              <td class="py-2">
                <div class="flex flex-wrap justify-end gap-1.5">
                  <button v-if="j.status !== 'queued' && j.status !== 'running'" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap"
                    @click="selectJob(j)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                    {{ t(`accounting.bank_bulk.action_result`) }}
                  </button>
                  <button v-if="canRun && j.kind === 'apply' && j.can_rollback" type="button" :class="btnOutlineSm('warning')" class="whitespace-nowrap"
                    :disabled="starting || isRunning" data-test="bank-bulk-rollback" @click="rollbackTarget = j">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                    {{ t(`accounting.bank_bulk.action_rollback`) }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ConfirmDialog
      v-if="confirmApply"
      :title="t(selected?.params.action === 'match' ? `accounting.bank_bulk.confirm_apply_title_match` : `accounting.bank_bulk.confirm_apply_title_post`)"
      :message="applyMessage"
      :confirm-label="applyLabel"
      :busy="starting"
      @confirm="runApply"
      @cancel="confirmApply = false"
    />
    <ConfirmDialog
      v-if="rollbackTarget"
      :title="t(`accounting.bank_bulk.confirm_rollback_title`)"
      :message="t(`accounting.bank_bulk.confirm_rollback`, { n: rollbackTarget.changed_count })"
      :confirm-label="t(`accounting.bank_bulk.action_rollback`)"
      variant="warning"
      :busy="starting"
      @confirm="runRollback"
      @cancel="rollbackTarget = null"
    />
  </section>
</template>

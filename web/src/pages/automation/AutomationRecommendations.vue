<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { automationRecommendationsApi, type AutomationRecommendation, type AutomationRecommendationsResult } from '@/api/automationRecommendations'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ExpenseRules from '@/pages/accounting/ExpenseRules.vue'
import RuleFormModal from '@/components/bank/RuleFormModal.vue'
import PostingPreviewModal from '@/components/accounting/PostingPreviewModal.vue'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import type { AutomationRecommendationsJob } from '@/api/automationRecommendations'

const props = defineProps<{ suppliers: number[] }>()
const { t, locale } = useI18n()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const result = ref<AutomationRecommendationsResult | null>(null)
const loading = ref(false)
const refreshing = ref(false)
const error = ref('')
const navigating = ref(false)
const activeProposal = ref<AutomationRecommendation | null>(null)
const completed = ref(new Set<string>())
const visibleItems = computed(() => result.value?.items.filter(item => !completed.value.has(item.id)) ?? [])
const savedMessage = ref('')
const type = ref('')
const from = ref('')
const to = ref('')
const page = ref(1)
const perPage = 30
let sequence = 0
let jobSequence = 0
let scopeSequence = 0
let startSequence = 0
let disposed = false
let cacheTimer: ReturnType<typeof setTimeout> | undefined
let jobTimer: ReturnType<typeof setTimeout> | undefined
const pending = computed(() => result.value?.snapshots.some(snapshot => snapshot.refresh_pending) ?? false)
const job = ref<AutomationRecommendationsJob | null>(null)
const jobScope = ref<number | null>(null)
const jobError = ref('')
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
const jobPercent = computed(() => {
  if (!job.value) return null
  const total = job.value.total_items ?? 6
  return Math.min(100, Math.round((job.value.processed / Math.max(1, total)) * 100))
})
const stepLabel = computed(() => {
  const step = job.value?.current_step
  const known = ['queued', 'waiting', 'invoices', 'purchases', 'expense_rules', 'bank_rules', 'coverage', 'publishing', 'completed']
  return step && known.includes(step) ? t(`automation.recommendations.job_step_${step}`) : step ? t('automation.recommendations.job_running') : ''
})
const jobProgress = computed(() => job.value ? ({ ...job.value, current_step: stepLabel.value, skipped_count: 0, failed_count: 0 }) : null)
const jobErrorText = computed(() => jobError.value || t('automation.recommendations.job_failed'))
const missing = computed(() => result.value?.snapshots.some(snapshot => !snapshot.generated_at) ?? false)
const oldestSnapshot = computed(() => result.value?.snapshots.map(snapshot => snapshot.generated_at).filter((date): date is string => !!date).sort()[0])

async function load(silent = false) {
  clearTimeout(cacheTimer)
  const current = ++sequence
  loading.value = !silent
  error.value = ''
  if (!props.suppliers.length) {
    result.value = { items: [], total: 0, page: 1, per_page: perPage, summary: { sales: 0, purchases: 0, bank: 0 }, snapshots: [] }
    loading.value = false
    return
  }
  try {
    const response = await automationRecommendationsApi.list({
      suppliers: props.suppliers.join(','), from: from.value || undefined, to: to.value || undefined,
      type: type.value || undefined, page: page.value, per_page: perPage,
    })
    if (sequence === current) result.value = response
  } catch {
    if (sequence === current) { if (!silent) result.value = null; error.value = t('automation.load_error') }
  } finally {
    if (sequence === current) {
      loading.value = false
      if (pending.value && !jobRunning.value) cacheTimer = setTimeout(() => { void load(true) }, 5000)
    }
  }
}

async function requestRefresh(afterSave = false) {
  if (props.suppliers.length !== 1 || (!afterSave && (refreshing.value || jobRunning.value))) return
  const supplier = props.suppliers[0]
  const scope = scopeSequence
  const current = ++startSequence
  const valid = () => !disposed && scopeSequence === scope && startSequence === current
  ++jobSequence
  clearTimeout(jobTimer)
  refreshing.value = true
  error.value = ''
  jobError.value = ''
  try {
    if (afterSave) {
      try { await automationRecommendationsApi.refresh(String(supplier)) } catch {}
    }
    if (!valid()) return
    const started = await automationRecommendationsApi.startJob(supplier)
    if (!valid()) return
    jobScope.value = supplier
    job.value = started
    await load(true)
    if (valid()) void pollJob(supplier)
  } catch {
    if (valid()) jobError.value = t('automation.recommendations.job_failed')
  } finally { if (valid()) refreshing.value = false }
}

async function pollJob(supplier: number) {
  clearTimeout(jobTimer)
  const current = ++jobSequence
  try {
    const next = await automationRecommendationsApi.getJob(supplier)
    if (current !== jobSequence || jobScope.value !== supplier || props.suppliers.join(',') !== String(supplier)) return
    job.value = next
    jobError.value = ''
    if (next?.status === 'queued' || next?.status === 'running') {
      jobTimer = setTimeout(() => { void pollJob(supplier) }, 2000)
    } else if (next?.status === 'completed') {
      completed.value = new Set()
      await load(true)
    }
  } catch {
    if (current === jobSequence && jobScope.value === supplier) {
      jobError.value = t('automation.recommendations.job_connection_error')
      if (jobRunning.value) jobTimer = setTimeout(() => { void pollJob(supplier) }, 4000)
    }
  }
}

async function restoreJob() {
  if (props.suppliers.length !== 1) return
  const supplier = props.suppliers[0]
  const current = ++jobSequence
  try {
    const existing = await automationRecommendationsApi.getJob(supplier)
    if (current !== jobSequence || props.suppliers.join(',') !== String(supplier)) return
    jobScope.value = supplier
    job.value = existing
    if (existing?.status === 'queued' || existing?.status === 'running') pollJob(supplier)
  } catch { if (current === jobSequence) jobError.value = t('automation.recommendations.job_connection_error') }
}

onUnmounted(() => { disposed = true; ++sequence; ++jobSequence; ++scopeSequence; clearTimeout(cacheTimer); clearTimeout(jobTimer) })

function canAct(item: AutomationRecommendation) {
  if (item.supplier_id !== supplierStore.currentSupplierId) return false
  if (item.action === 'create_expense_rule') return !!item.rule_payload && auth.canWrite('accounting')
  if (item.action === 'edit_expense_rule') return !!item.rule_id && auth.canWrite('accounting')
  if (item.action === 'create_bank_rule') return !!item.rule_payload && auth.canWrite('bank.rules')
  return item.action === 'post_document' && !item.period_closed && !item.booked && !item.preview_error
    && auth.canWrite('accounting.journal.post')
}

function act(item: AutomationRecommendation) {
  if (canAct(item)) activeProposal.value = item
}

async function resolved() {
  const item = activeProposal.value
  if (!item) return
  activeProposal.value = null
  completed.value.add(item.id)
  savedMessage.value = t(item.action === 'post_document' ? 'automation.recommendations.posted_done' : item.action === 'edit_expense_rule' ? 'automation.recommendations.rule_updated' : 'automation.recommendations.rule_created')
  await requestRefresh(true)
}

async function open(item: AutomationRecommendation) {
  if (navigating.value) return
  navigating.value = true
  error.value = ''
  try {
    if (supplierStore.currentSupplierId !== item.supplier_id) throw new Error('supplier_changed')
    if (item.type === 'bank_rule') await router.push({ name: 'bank-detail', params: { id: item.statement_id }, query: { tx: String(item.transaction_id) } })
    else await router.push({ name: item.type === 'post_invoice' ? 'invoice-detail' : 'purchase-invoice-detail', params: { id: item.document_id } })
  } catch { error.value = t('automation.load_error') } finally { navigating.value = false }
}

function money(item: AutomationRecommendation) {
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: item.currency }).format(item.amount)
}
function reason(item: AutomationRecommendation) {
  if (['repeated_expense_pattern', 'repeated_bank_pattern', 'review_expense_rule'].includes(item.reason)) return t(`automation.recommendations.${item.reason}`)
  if (item.type === 'classify_purchase' || item.action === 'create_bank_rule') return item.reason
  return t(`automation.recommendations.reason_${item.type}`)
}

watch([() => props.suppliers.join(','), type, from, to], () => {
  if (page.value !== 1) page.value = 1
  else void load()
}, { immediate: true })
watch(() => props.suppliers.join(','), () => {
  ++scopeSequence
  ++jobSequence
  clearTimeout(jobTimer)
  job.value = null
  jobScope.value = null
  jobError.value = ''
  refreshing.value = false
  completed.value = new Set()
  void restoreJob()
}, { immediate: true })
watch(page, () => { void load() })
</script>

<template>
  <section class="space-y-4">
    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4">
      <h2 class="font-semibold text-primary-800">{{ t('automation.recommendations.title') }}</h2>
      <p class="mt-1 text-sm text-primary-700">{{ t('automation.recommendations.description') }}</p>
      <p class="mt-1 text-xs text-primary-700">{{ t('automation.recommendations.rules_safety_hint') }}</p>
      <p v-if="result" class="mt-2 text-sm text-primary-800">{{ t('automation.recommendations.coverage', result.summary) }}</p>
      <p v-if="oldestSnapshot" class="mt-2 text-xs text-primary-800">{{ t('automation.recommendations.last_updated', { date: oldestSnapshot }) }}</p>
      <p class="mt-1 text-xs text-primary-700">{{ t('automation.recommendations.background_hint') }}</p>
      <p v-if="pending && !jobRunning && job?.status !== 'failed'" role="status" class="mt-2 text-sm text-primary-800">{{ t(missing ? 'automation.recommendations.waiting_first' : 'automation.recommendations.refresh_pending') }}</p>
      <ImportJobProgress v-if="jobRunning" class="mt-3" :job="jobProgress" :percent="jobPercent" :cancelling="false"
        :show-cancel="false"
        :counts-key="'automation.recommendations.job_counts'" :background-hint-key="'automation.recommendations.job_hint'"
        :running-key="'automation.recommendations.job_running'" />
      <p v-if="job?.status === 'completed'" role="status" class="mt-3 text-sm text-success-700">{{ t('automation.recommendations.job_completed', { n: job.created_count, date: job.finished_at }) }}</p>
      <div v-if="job?.status === 'failed' || jobError" role="alert" class="mt-3 flex flex-wrap items-center gap-2 text-sm text-danger-700">
        <span>{{ jobErrorText }}</span>
        <button v-if="!jobRunning" type="button" :class="btnOutline('danger')" :disabled="refreshing" @click="requestRefresh()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('automation.recommendations.job_retry') }}</button>
      </div>
    </div>
    <div class="flex flex-wrap items-end gap-3">
      <label class="text-xs text-neutral-500">{{ t('automation.recommendations.filter') }}
        <select v-model="type" class="mt-1 block rounded border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="kind in ['post_invoice', 'post_purchase', 'classify_purchase', 'bank_rule']" :key="kind" :value="kind">{{ t(`automation.recommendations.${kind}`) }}</option>
        </select>
      </label>
      <label class="text-xs text-neutral-500">{{ t('common.from') }}<input v-model="from" type="date" class="mt-1 block rounded border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
      <label class="text-xs text-neutral-500">{{ t('common.to') }}<input v-model="to" type="date" class="mt-1 block rounded border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading || refreshing || jobRunning || !suppliers.length" @click="requestRefresh()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('automation.recommendations.refresh') }}</button>
    </div>
    <p class="text-xs text-neutral-500">{{ t('automation.recommendations.date_filter_hint') }}</p>
    <p v-if="error" role="alert" class="rounded bg-danger-50 p-4 text-danger-600">{{ error }}</p>
    <p v-if="savedMessage" role="status" class="rounded bg-success-50 p-4 text-success-700">{{ savedMessage }}</p>
    <p v-if="loading" class="py-10 text-center text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else-if="result">
      <p v-if="(!result.total || !visibleItems.length) && !missing" class="rounded-lg border border-neutral-200 bg-surface p-6 text-neutral-600">{{ t('automation.recommendations.empty') }}</p>
      <div class="grid gap-3 xl:grid-cols-2 2xl:grid-cols-3">
      <article v-for="item in visibleItems" :key="item.id" class="flex min-w-0 flex-col gap-2 rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap gap-2 text-xs text-neutral-500"><span v-if="suppliers.length > 1">{{ item.supplier_name }}</span><span>{{ item.date }}</span><span>{{ item.document_no }}</span><span class="rounded bg-primary-50 px-2 py-0.5 text-primary-700">{{ t(item.action === 'edit_expense_rule' ? 'automation.recommendations.existing_expense_rule' : item.action === 'create_expense_rule' ? 'automation.recommendations.new_expense_rule' : `automation.recommendations.${item.type}`) }}</span></div>
            <h3 class="mt-2 break-words font-semibold">{{ item.description || item.document_no }}</h3>
            <p v-if="item.action !== 'edit_expense_rule'" class="text-sm text-neutral-500">{{ item.counterparty }}</p>
          </div>
          <div class="text-right"><span v-if="item.rule_payload" class="block text-xs text-neutral-500">{{ t(item.type === 'bank_rule' ? 'automation.recommendations.maximum_amount' : 'automation.recommendations.example_amount') }}</span><strong class="whitespace-nowrap">{{ money(item) }}</strong></div>
        </div>
        <p class="text-sm text-neutral-700">{{ reason(item) }}</p>
        <p v-if="item.occurrence_count" class="text-sm font-medium text-primary-700">{{ t(item.action === 'edit_expense_rule' ? 'automation.recommendations.existing_occurrences' : 'automation.recommendations.occurrences', { n: item.occurrence_count }) }}</p>
        <p v-if="item.rule_payload?.description_contains || item.rule_payload?.message_contains" class="text-sm text-neutral-700">{{ t(item.action === 'edit_expense_rule' ? 'automation.recommendations.existing_match_text' : 'automation.recommendations.match_text') }}: <strong>{{ item.rule_payload.description_contains || item.rule_payload.message_contains }}</strong></p>
        <p v-if="item.action === 'create_bank_rule'" class="font-mono text-sm">{{ t('automation.recommendations.debit') }} {{ item.rule_payload?.debit_account_code || '…' }} / {{ t('automation.recommendations.credit') }} {{ item.rule_payload?.credit_account_code || '…' }}</p>
        <details v-if="item.samples?.length" class="text-sm">
          <summary class="cursor-pointer text-primary-700">{{ t(item.action === 'edit_expense_rule' ? 'automation.recommendations.existing_evidence' : 'automation.recommendations.evidence') }}</summary>
          <ul class="mt-2 space-y-1 text-neutral-600"><li v-for="(sample, index) in item.samples" :key="index">{{ sample.date }} {{ sample.description }}</li></ul>
        </details>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <span v-if="item.expense_kind" class="rounded bg-neutral-50 px-2 py-0.5 text-xs text-neutral-600">{{ t(`automation.recommendations.kind_${item.expense_kind}`) }}</span>
          <span v-if="item.suggested_account_code"><span class="text-neutral-500">{{ t(item.action === 'edit_expense_rule' ? 'automation.recommendations.existing_account' : 'automation.recommendations.account') }}: </span><span v-if="item.current_account_code && item.action !== 'edit_expense_rule'" class="font-mono">{{ item.current_account_code }} → </span><strong class="font-mono text-primary-700">{{ item.suggested_account_code }}</strong></span>
        </div>
        <p v-if="item.confidence !== null && item.action !== 'edit_expense_rule'" class="text-xs text-neutral-500">{{ t('automation.recommendations.confidence', { n: Math.round(item.confidence * 100) }) }}</p>
        <details v-if="item.lines?.length" class="text-sm">
          <summary class="cursor-pointer text-primary-700">{{ t('automation.recommendations.preview') }}</summary>
          <div class="mt-2 overflow-x-auto"><table class="w-full text-left"><tbody><tr v-for="(line, index) in item.lines" :key="index" class="border-b border-neutral-100"><td class="p-2 font-mono">{{ line.account_code }}</td><td class="p-2">{{ t(`automation.recommendations.${line.side}`) }}</td><td class="p-2 text-right">{{ new Intl.NumberFormat(locale, { style: 'currency', currency: 'CZK' }).format(line.amount) }}</td></tr></tbody></table></div>
        </details>
        <p v-if="item.preview_error" class="text-sm text-warning-700">{{ t('automation.recommendations.preview_unavailable') }}</p>
        <p v-if="!item.rule_payload && item.period_closed" class="text-sm text-warning-700">{{ t('automation.recommendations.period_closed') }}</p>
        <div class="mt-auto flex flex-wrap gap-2 pt-2">
          <button v-if="canAct(item)" type="button" :class="btnFilled('primary')" @click="act(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t(`automation.recommendations.action_${item.action}`) }}</button>
          <button v-if="item.document_id || (item.statement_id && item.transaction_id)" type="button" :class="btnOutline('neutral')" :disabled="navigating" @click="open(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('automation.recommendations.open') }}</button>
        </div>
      </article>
      </div>
      <PaginationBar :page="page" :per-page="perPage" :total="result.total" @update:page="page = $event" />
    </template>
    <ExpenseRules v-if="activeProposal?.action === 'create_expense_rule' || activeProposal?.action === 'edit_expense_rule'"
      :initial-draft="{ vendorId: activeProposal.vendor_id ?? null, vendorName: activeProposal.counterparty, rule: activeProposal.rule_payload ?? undefined, ...(activeProposal.action === 'edit_expense_rule' ? { ruleId: activeProposal.rule_id! } : {}) }"
      @close="activeProposal = null" @saved="resolved" />
    <Teleport to="body">
      <RuleFormModal v-if="activeProposal?.action === 'create_bank_rule'" :prefill="(activeProposal.rule_payload ?? undefined) as any"
        @close="activeProposal = null" @saved="resolved" />
    </Teleport>
    <PostingPreviewModal v-if="activeProposal?.action === 'post_document' && activeProposal.document_id" open
      :source="activeProposal.type === 'post_invoice' ? 'invoices' : 'purchase-invoices'" :doc-id="activeProposal.document_id" :doc-label="activeProposal.document_no"
      @close="activeProposal = null" @posted="resolved" />
  </section>
</template>

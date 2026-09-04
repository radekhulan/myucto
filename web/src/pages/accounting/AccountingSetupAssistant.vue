<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingSetupAssistantApi,
  type ReclassificationItem,
  type ReclassificationLine,
  type SetupJob,
  type SetupProposal,
  type SetupProposalUpdate,
  type SetupRun,
} from '@/api/accountingSetupAssistant'
import { apiErrorMessage } from '@/api/errors'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { dependentProposalIds, requiredChartProposalIds } from '@/utils/accountingSetupDependencies'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const runs = ref<SetupRun[]>([])
const analysisJobs = ref<SetupJob[]>([])
const jobs = ref<SetupJob[]>([])
const dryRunItems = ref<ReclassificationItem[]>([])
const proposals = ref<SetupProposal[]>([])
const accounts = ref<ChartAccount[]>([])
const selected = ref(new Set<number>())
const loading = ref(true)
const busy = ref(false)
const cancelling = ref(false)
const activeJob = ref<SetupJob | null>(null)
const dateFrom = ref('')
const dateTo = ref('')
const historyDateFrom = ref('')
const historyDateTo = ref('')
const historyScopeMode = ref<'matched' | 'all'>('matched')
const activeExpenseRuleCount = ref(0)
const aiAvailable = ref(false)
const useAi = ref(false)
const aiSampleLimit = ref<50 | 100 | 200>(50)
const activeType = ref<SetupProposal['proposal_type'] | null>(null)
const sourceFilter = ref<'all' | 'catalog' | 'ai' | 'history'>('all')
const editingProposal = ref<SetupProposal | null>(null)
const previewEntryId = ref<number | null>(null)
const editSaving = ref(false)
const editForm = ref({
  name: '',
  create: true,
  replacement_account_code: '',
  description: '',
  description_contains: '',
  message_contains: '',
  expense_kind: 'service',
  target_account_code: '',
  debit_account_code: '',
  credit_account_code: '',
})
let timer: ReturnType<typeof setTimeout> | null = null

const run = computed(() => runs.value[0] ?? null)
const bundleId = computed(() => run.value?.bundle_id ?? null)
const canApprove = computed(() => auth.canWrite('accounting.templates'))
const canPost = computed(() => auth.canWrite('accounting.journal.post'))
const latestApplyJobId = computed(() => Math.max(0, ...jobs.value
  .filter(j => j.params?.dry_run === false && !j.params?.rollback_of_job_id
    && (j.status === 'completed' || j.status === 'completed_with_warnings'))
  .map(j => j.id)))
const dryRunJob = computed(() => jobs.value.find(j => j.params?.dry_run === true
  && Number(j.params?.bundle_id) === bundleId.value
  && String(j.params?.bundle_hash || '') === String(run.value?.bundle_hash || '')
  && String(j.params?.date_from ?? '') === historyDateFrom.value
  && String(j.params?.date_to ?? '') === historyDateTo.value
  && String(j.params?.scope_mode ?? 'matched') === historyScopeMode.value
  && j.id > latestApplyJobId.value
  && (j.status === 'completed' || j.status === 'completed_with_warnings')) ?? null)
const appliedJob = computed(() => jobs.value.find(j => j.params?.dry_run === false
  && !j.params?.rollback_of_job_id
  && j.rollback_snapshot_available === true
  && Number(j.params?.bundle_id) === bundleId.value
  && String(j.params?.bundle_hash || '') === String(run.value?.bundle_hash || '')
  && (j.status === 'completed' || j.status === 'completed_with_warnings')) ?? null)
const rollbackExists = computed(() => appliedJob.value !== null && jobs.value.some(j =>
  Number(j.params?.rollback_of_job_id) === appliedJob.value?.id
  && j.status !== 'failed' && j.status !== 'cancelled'))
const historyRangeValid = computed(() => !historyDateFrom.value || !historyDateTo.value
  || historyDateFrom.value <= historyDateTo.value)
const canPrepareBundle = computed(() => selected.value.size > 0 || activeExpenseRuleCount.value > 0)
const hasActiveWork = computed(() => [...analysisJobs.value, ...jobs.value]
  .some(j => j.status === 'queued' || j.status === 'running'))
const progress = computed(() => {
  if (!activeJob.value?.total_items) return null
  return Math.min(100, Math.round(activeJob.value.processed / activeJob.value.total_items * 100))
})
const localizedActiveJob = computed(() => activeJob.value === null ? null : {
  ...activeJob.value,
  current_step: jobStep(activeJob.value.current_step),
})
const activeAnalysisJob = computed(() => localizedActiveJob.value?.source === 'accounting_setup_analysis'
  ? localizedActiveJob.value : null)
const activeReclassificationJob = computed(() => localizedActiveJob.value?.source === 'accounting_history_reclassification'
  ? localizedActiveJob.value : null)
const classificationCoverage = computed(() => {
  const summary = run.value?.summary_json
  if (!summary?.items) return 0
  return summary.classification_coverage_pct
    ?? Math.round(1000 * Math.max(0, summary.items - summary.unclassified) / summary.items) / 10
})
const dryRunChanged = computed(() => dryRunItems.value.filter(item => item.status === 'would_change').length)
const dryRunCoverage = computed(() => dryRunItems.value.length === 0
  ? 0
  : Math.round(1000 * dryRunChanged.value / dryRunItems.value.length) / 10)
const typeOrder = ['expense_rule', 'chart_account', 'posting_rule', 'bank_rule', 'asset_candidate', 'data_quality'] as const
const availableTypes = computed(() => typeOrder
  .map(type => ({ type, count: proposals.value.filter(p => p.proposal_type === type).length }))
  .filter(item => item.count > 0))
const sourceOptions = ['all', 'catalog', 'ai', 'history'] as const
const visibleProposals = computed(() => proposals.value.filter(item =>
  item.proposal_type === activeType.value
  && (sourceFilter.value === 'all' || proposalSource(item) === sourceFilter.value)))
const sourceCounts = computed(() => Object.fromEntries(sourceOptions.map(source => [
  source,
  proposals.value.filter(item => item.proposal_type === activeType.value
    && (source === 'all' || proposalSource(item) === source)).length,
])))
const proposedAccounts = computed<ChartAccount[]>(() => proposals.value
  .filter(item => item.proposal_type === 'chart_account' && item.proposal_json.create !== false)
  .map(item => ({
    id: -item.id,
    supplier_id: 0,
    account_code: String(item.proposal_json.account_code || ''),
    name: String(item.proposal_json.name || item.title),
    account_type: String(item.proposal_json.account_type || 'expense') as ChartAccount['account_type'],
    normal_side: (item.proposal_json.normal_side || null) as ChartAccount['normal_side'],
    is_synthetic: false,
    parent_id: null,
    is_active: true,
    created_at: '',
  }))
  .filter(account => account.account_code !== ''))
const allAccountOptions = computed(() => [...accounts.value, ...proposedAccounts.value]
  .filter((account, index, rows) => account.is_active
    && rows.findIndex(candidate => candidate.account_code === account.account_code) === index)
  .sort((a, b) => a.account_code.localeCompare(b.account_code))
  .map(account => ({ value: account.account_code, label: `${account.account_code} - ${account.name}` })))
const expenseAccountOptions = computed(() => [...accounts.value, ...proposedAccounts.value]
  .filter((account, index, rows) => account.is_active && !account.is_synthetic && account.account_type === 'expense'
    && rows.findIndex(candidate => candidate.account_code === account.account_code) === index)
  .sort((a, b) => a.account_code.localeCompare(b.account_code))
  .map(account => ({ value: account.account_code, label: `${account.account_code} - ${account.name}` })))
const analyticAccountOptions = computed(() => [...accounts.value, ...proposedAccounts.value]
  .filter((account, index, rows) => account.is_active && !account.is_synthetic
    && rows.findIndex(candidate => candidate.account_code === account.account_code) === index)
  .sort((a, b) => a.account_code.localeCompare(b.account_code))
  .map(account => ({ value: account.account_code, label: `${account.account_code} - ${account.name}` })))
const replacementAccountOptions = computed(() => accounts.value
  .filter(account => account.is_active && !account.is_synthetic)
  .sort((a, b) => a.account_code.localeCompare(b.account_code))
  .map(account => ({ value: account.account_code, label: `${account.account_code} - ${account.name}` })))
const editingDependencyCount = computed(() => {
  if (editingProposal.value?.proposal_type !== 'chart_account') return 0
  const tracked = editingProposal.value.proposal_json.dependent_proposal_ids
  if (Array.isArray(tracked)) return tracked.length
  const accountCode = String(editingProposal.value.proposal_json.account_code || '')
  return dependentProposalIds(proposals.value, accountCode).length
})

watch(availableTypes, groups => {
  if (!groups.some(group => group.type === activeType.value)) {
    activeType.value = groups[0]?.type ?? null
  }
}, { immediate: true })

watch([historyDateFrom, historyDateTo, historyScopeMode], () => {
  dryRunItems.value = []
})

function icon(path: string) {
  return path
}

async function load() {
  loading.value = true
  try {
    const [status, chart] = await Promise.all([
      accountingSetupAssistantApi.status(),
      accounts.value.length ? Promise.resolve(accounts.value) : accountingApi.listAccounts(),
    ])
    accounts.value = chart
    runs.value = status.runs
    aiAvailable.value = status.ai_available
    if (!status.ai_available) useAi.value = false
    analysisJobs.value = status.analysis_jobs
    jobs.value = status.reclassification_jobs
    activeExpenseRuleCount.value = status.active_expense_rule_count
    if (run.value?.completed_at) {
      proposals.value = (await accountingSetupAssistantApi.proposals(run.value.id)).items
      selected.value = new Set(proposals.value.filter(p => p.decision === 'pending' && (
        ((p.proposal_type !== 'chart_account' || p.proposal_json.create !== false)
          && ['chart_account', 'expense_rule', 'posting_rule'].includes(p.proposal_type))
        || (p.proposal_type === 'bank_rule' && auth.canWrite('bank.rules'))
      ))
        .map(p => p.id))
    }
    if (dryRunJob.value) {
      dryRunItems.value = (await accountingSetupAssistantApi.job(dryRunJob.value.id)).items ?? []
    } else {
      dryRunItems.value = []
    }
    const running = [...status.analysis_jobs, ...status.reclassification_jobs]
      .find(j => j.status === 'queued' || j.status === 'running')
    if (running && activeJob.value?.id !== running.id) {
      activeJob.value = running
      poll(running.id)
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

function stopPolling() {
  if (timer) clearTimeout(timer)
  timer = null
}

function poll(jobId: number) {
  stopPolling()
  let failures = 0
  const tick = async () => {
    try {
      const response = await accountingSetupAssistantApi.job(jobId)
      activeJob.value = response.job
      if (response.job.params?.dry_run === true && response.items) {
        dryRunItems.value = response.items
      }
      failures = 0
      if (response.job.status === 'queued' || response.job.status === 'running') {
        timer = setTimeout(tick, 1500)
        return
      }
      await load()
      if (response.job.status === 'failed') toast.error(response.job.last_error || t('accounting.setup_assistant.failed'))
      else if (response.job.status === 'cancelled') toast.warning(t('accounting.setup_assistant.cancelled'))
      else toast.success(t('accounting.setup_assistant.job_done'))
    } catch (e) {
      failures++
      if (failures < 5) timer = setTimeout(tick, 3000)
      else toast.error(apiErrorMessage(e))
    }
  }
  void tick()
}

async function startAnalysis() {
  busy.value = true
  try {
    const result = await accountingSetupAssistantApi.startAnalysis({
      date_from: dateFrom.value || null,
      date_to: dateTo.value || null,
      use_ai: useAi.value,
      ai_sample_limit: aiSampleLimit.value,
    })
    poll(result.job_id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function toggle(id: number) {
  const proposal = proposals.value.find(item => item.id === id)
  if (!proposal || !isSelectable(proposal)) return
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  const accountCode = String(proposal.proposal_json.account_code || '')
  if (proposal.proposal_type === 'chart_account' && accountCode && !next.has(id)) {
    for (const dependentId of dependentProposalIds(proposals.value, accountCode)) next.delete(dependentId)
  } else if (next.has(id)) {
    for (const dependencyId of requiredChartProposalIds(proposals.value, proposal)) next.add(dependencyId)
  }
  selected.value = next
}

function isSelectable(item: SetupProposal): boolean {
  if (item.proposal_type === 'data_quality') return false
  if (item.proposal_type === 'chart_account' && item.proposal_json.create === false) return false
  return item.proposal_type !== 'bank_rule' || auth.canWrite('bank.rules')
}

function canEdit(item: SetupProposal): boolean {
  return !bundleId.value && item.decision === 'pending' && canApprove.value
    && ['chart_account', 'expense_rule', 'posting_rule', 'bank_rule'].includes(item.proposal_type)
    && (item.proposal_type !== 'bank_rule' || auth.canWrite('bank.rules'))
}

function openEdit(item: SetupProposal) {
  const proposal = item.proposal_json
  editForm.value = {
    name: String(proposal.name || item.title || ''),
    create: proposal.create !== false,
    replacement_account_code: String(proposal.replacement_account_code || ''),
    description: String(proposal.description || ''),
    description_contains: String(proposal.description_contains || ''),
    message_contains: String(proposal.message_contains || ''),
    expense_kind: String(proposal.expense_kind || item.evidence_json.expense_kind || 'service'),
    target_account_code: String(proposal.target_account_code || ''),
    debit_account_code: String(proposal.debit_account_code || ''),
    credit_account_code: String(proposal.credit_account_code || ''),
  }
  editingProposal.value = item
}

async function saveEdit() {
  if (!run.value || !editingProposal.value) return
  const item = editingProposal.value
  let payload: SetupProposalUpdate
  if (item.proposal_type === 'chart_account') {
    payload = {
      name: editForm.value.name,
      create: editForm.value.create,
      replacement_account_code: editForm.value.create ? null : editForm.value.replacement_account_code,
    }
  } else if (item.proposal_type === 'expense_rule') {
    payload = {
      name: editForm.value.name,
      description_contains: editForm.value.description_contains,
      expense_kind: editForm.value.expense_kind,
      target_account_code: editForm.value.target_account_code,
    }
  } else if (item.proposal_type === 'posting_rule') {
    payload = {
      description: editForm.value.description,
      debit_account_code: editForm.value.debit_account_code,
      credit_account_code: editForm.value.credit_account_code,
    }
  } else if (item.proposal_type === 'bank_rule') {
    payload = {
      name: editForm.value.name,
      message_contains: editForm.value.message_contains || null,
      debit_account_code: editForm.value.debit_account_code,
      credit_account_code: editForm.value.credit_account_code,
    }
  } else {
    return
  }
  editSaving.value = true
  try {
    const result = await accountingSetupAssistantApi.updateProposal(run.value.id, item.id, payload)
    proposals.value = item.proposal_type === 'chart_account'
      ? (await accountingSetupAssistantApi.proposals(run.value.id)).items
      : proposals.value.map(proposal => proposal.id === item.id ? result.proposal : proposal)
    const next = new Set(selected.value)
    if (item.proposal_type === 'chart_account') {
      if (result.proposal.proposal_json.create === false) next.delete(item.id)
      else next.add(item.id)
    }
    if (selected.value.has(item.id)) {
      for (const dependencyId of requiredChartProposalIds(proposals.value, result.proposal)) next.add(dependencyId)
    }
    selected.value = next
    editingProposal.value = null
    toast.success(t('accounting.setup_assistant.edit_saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    editSaving.value = false
  }
}

async function approve() {
  if (!run.value || !canPrepareBundle.value) return
  busy.value = true
  try {
    await accountingSetupAssistantApi.approve(run.value.id, [...selected.value])
    toast.success(t('accounting.setup_assistant.approved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function startReclassification(dryRun: boolean) {
  if (!bundleId.value) return
  if (!dryRun && !window.confirm(t('accounting.setup_assistant.apply_confirm'))) return
  busy.value = true
  try {
    const result = await accountingSetupAssistantApi.startReclassification(
      bundleId.value,
      dryRun,
      dryRun ? undefined : dryRunJob.value?.id,
      historyDateFrom.value || null,
      historyDateTo.value || null,
      historyScopeMode.value,
    )
    poll(result.job_id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function rollback() {
  if (!appliedJob.value || !window.confirm(t('accounting.setup_assistant.rollback_confirm'))) return
  busy.value = true
  try {
    const result = await accountingSetupAssistantApi.rollback(appliedJob.value.id)
    poll(result.job_id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function deleteSnapshot() {
  if (!appliedJob.value || !window.confirm(t('accounting.setup_assistant.delete_snapshot_confirm'))) return
  busy.value = true
  try {
    await accountingSetupAssistantApi.deleteSnapshot(appliedJob.value.id)
    await load()
    toast.success(t('accounting.setup_assistant.snapshot_deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function cancel() {
  if (!activeJob.value) return
  cancelling.value = true
  try {
    await accountingSetupAssistantApi.cancel(activeJob.value.id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    cancelling.value = false
  }
}

function proposalSummary(item: SetupProposal): string {
  const p = item.proposal_json
  if (item.proposal_type === 'expense_rule') {
    return `${expenseKindLabel(String(p.expense_kind || ''))}${p.target_account_code ? ` → ${String(p.target_account_code)}` : ''}`
  }
  if (item.proposal_type === 'chart_account') {
    if (p.create === false) {
      return `${String(p.account_code || '?')} → ${String(p.replacement_account_code || '?')}`
    }
    return `${String(p.parent_account_code || '?')} → ${String(p.account_code || '?')}`
  }
  if (item.proposal_type === 'posting_rule') {
    return `${expenseKindLabel(String(item.evidence_json.expense_kind || p.rule_key || ''))}: ${String(p.debit_account_code || '?')} / ${String(p.credit_account_code || '?')}`
  }
  if (item.proposal_type === 'bank_rule') {
    return `${String(p.debit_account_code || '?')} / ${String(p.credit_account_code || '?')}`
  }
  if (item.proposal_type === 'asset_candidate') {
    return `${Number(p.unit_price_czk || 0).toLocaleString()} Kč > ${Number(p.fixed_asset_limit || 0).toLocaleString()} Kč`
  }
  return t('accounting.setup_assistant.needs_review')
}

function proposalTitle(item: SetupProposal): string {
  if (item.proposal_type === 'asset_candidate') {
    return t('accounting.setup_assistant.asset_candidate_title', {
      name: String(item.proposal_json.item_description || item.evidence_json.sample || item.title),
    })
  }
  if (item.proposal_type !== 'posting_rule') return item.title
  const p = item.proposal_json
  return t('accounting.setup_assistant.posting_title', {
    kind: expenseKindLabel(String(item.evidence_json.expense_kind || p.rule_key || '')),
    debit: String(p.debit_account_code || '?'),
    credit: String(p.credit_account_code || '?'),
  })
}

function expenseKindLabel(value: string): string {
  const key = value.startsWith('invoice.')
    ? ({
        'invoice.services.received': 'service',
        'invoice.material.received': 'material',
        'invoice.small_asset.received': 'small_asset',
        'invoice.small_intangible.received': 'small_intangible',
        'invoice.dhm.received': 'fixed_asset',
      } as Record<string, string>)[value] || 'other'
    : value
  return t(`accounting.setup_assistant.expense_kinds.${key}`)
}

function conceptLabel(value: unknown): string {
  const key = String(value || '')
  const translated = t(`accounting.setup_assistant.concepts.${key}`)
  return translated === `accounting.setup_assistant.concepts.${key}` ? key : translated
}

function proposalSource(item: SetupProposal): 'catalog' | 'ai' | 'history' {
  if (item.evidence_json.source === 'ai') return 'ai'
  if (item.evidence_json.source === 'catalog') return 'catalog'
  if (item.evidence_json.source === 'history') return 'history'
  if (item.proposal_type === 'bank_rule' || item.proposal_type === 'data_quality') return 'history'
  return 'catalog'
}

function accountList(lines: ReclassificationLine[] | undefined): string {
  return (lines ?? []).map(line => `${line.account_code} ${line.side === 'debit' ? 'MD' : 'D'} ${line.amount.toLocaleString()}`).join(', ')
}

function jobStatus(status: SetupJob['status']): string {
  return t(`accounting.setup_assistant.statuses.${status}`)
}

function jobStep(step: string | null): string | null {
  if (!step) return null
  const key = `accounting.setup_assistant.steps.${step}`
  const translated = t(key)
  return translated === key ? step : translated
}

function reclassificationStatus(status: ReclassificationItem['status']): string {
  return t(`accounting.setup_assistant.reclassification_statuses.${status}`)
}

function reclassificationError(code: string): string {
  const key = `accounting.setup_assistant.reclassification_errors.${code}`
  const translated = t(key)
  return translated === key ? code : translated
}

function sourceEntryId(item: ReclassificationItem): number | null {
  const id = item.correction_entry_id ?? item.before_json?.entry_id
  return id && id > 0 ? Number(id) : null
}

onMounted(load)
onBeforeUnmount(stopPolling)
</script>

<template>
  <div class="max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('accounting.setup_assistant.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('accounting.setup_assistant.subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-neutral-700">
      <div class="font-medium text-primary-800">{{ t('accounting.setup_assistant.safety_title') }}</div>
      <p class="mt-1">{{ t('accounting.setup_assistant.safety') }}</p>
    </div>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-lg font-semibold">1. {{ t('accounting.setup_assistant.analysis_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('accounting.setup_assistant.analysis_help') }}</p>
        </div>
        <button v-if="canApprove" :disabled="busy || hasActiveWork" :class="btnFilled('primary')" @click="startAnalysis">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.search)" /></svg>
          {{ t('accounting.setup_assistant.start_analysis') }}
        </button>
      </div>
      <ImportJobProgress
        class="mt-4"
        :job="activeAnalysisJob"
        :percent="progress"
        :cancelling="cancelling"
        counts-key="accounting.setup_assistant.job_counts"
        background-hint-key="accounting.setup_assistant.background_hint"
        running-key="accounting.setup_assistant.job_running"
        cancel-key="accounting.setup_assistant.job_cancel"
        cancelling-key="accounting.setup_assistant.job_cancelling"
        @cancel="cancel"
      />
      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-sm">{{ t('accounting.setup_assistant.date_from') }}<input v-model="dateFrom" type="date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" /></label>
        <label class="text-sm">{{ t('accounting.setup_assistant.date_to') }}<input v-model="dateTo" type="date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" /></label>
      </div>
      <label v-if="aiAvailable" class="mt-4 flex cursor-pointer items-start gap-3 rounded-md border border-primary-200 bg-primary-50 p-3 text-sm">
        <input v-model="useAi" type="checkbox" class="mt-1" />
        <span>
          <span class="font-medium text-primary-800">{{ t('accounting.setup_assistant.ai_enable') }}</span>
          <span class="mt-1 block text-neutral-600">{{ t('accounting.setup_assistant.ai_disclosure') }}</span>
        </span>
      </label>
      <label v-if="aiAvailable && useAi" class="mt-3 block max-w-sm text-sm">
        {{ t('accounting.setup_assistant.ai_sample_limit') }}
        <select v-model.number="aiSampleLimit" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option :value="50">{{ t('accounting.setup_assistant.ai_sample_option', { samples: 50, requests: 1 }) }}</option>
          <option :value="100">{{ t('accounting.setup_assistant.ai_sample_option', { samples: 100, requests: 2 }) }}</option>
          <option :value="200">{{ t('accounting.setup_assistant.ai_sample_option', { samples: 200, requests: 4 }) }}</option>
        </select>
        <span class="mt-1 block text-xs text-neutral-500">{{ t('accounting.setup_assistant.ai_sample_hint') }}</span>
      </label>
      <p v-if="!aiAvailable" class="mt-4 text-xs text-neutral-500">{{ t('accounting.setup_assistant.ai_unavailable') }}</p>
      <div v-if="run?.summary_json" class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-6 text-sm">
        <div><div class="text-xl font-semibold">{{ run.summary_json.documents }}</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.documents') }}</div></div>
        <div><div class="text-xl font-semibold">{{ run.summary_json.items }}</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.items') }}</div></div>
        <div><div class="text-xl font-semibold">{{ proposals.length }}</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.proposals') }}</div></div>
        <div><div class="text-xl font-semibold">{{ run.summary_json.unclassified }}</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.unclassified') }}</div></div>
        <div><div class="text-xl font-semibold">{{ classificationCoverage.toLocaleString(undefined, { maximumFractionDigits: 1 }) }} %</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.coverage') }}</div></div>
        <div><div class="text-xl font-semibold">{{ run.summary_json.locked_period_documents }}</div><div class="text-neutral-500">{{ t('accounting.setup_assistant.locked') }}</div></div>
      </div>
      <p v-if="run?.summary_json" class="mt-2 text-xs text-neutral-500">{{ t('accounting.setup_assistant.coverage_hint') }}</p>
      <p v-if="run?.summary_json" class="mt-3 text-xs text-neutral-500">
        {{ t('accounting.setup_assistant.catalog', { version: run.summary_json.catalog_version, locales: run.summary_json.catalog_locales.join(', ').toUpperCase() }) }}
      </p>
      <p v-if="run?.summary_json?.ai?.requested" class="mt-2 text-xs text-neutral-500">
        {{ t('accounting.setup_assistant.ai_result', {
          status: t(`accounting.setup_assistant.ai_statuses.${run.summary_json.ai.status}`),
          samples: run.summary_json.ai.samples_sent,
          requests: run.summary_json.ai.requests_sent ?? (run.summary_json.ai.samples_sent > 0 ? 1 : 0),
          classified: run.summary_json.ai.classified_items,
          proposals: run.summary_json.ai.proposals,
        }) }}
      </p>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-lg font-semibold">2. {{ t('accounting.setup_assistant.review_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('accounting.setup_assistant.review_help') }}</p>
        </div>
        <button v-if="canApprove && !bundleId" :disabled="busy || !canPrepareBundle" :class="btnFilled('success')" @click="approve">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check" /></svg>
          {{ selected.size > 0
            ? t('accounting.setup_assistant.approve_selected', { count: selected.size })
            : t('accounting.setup_assistant.prepare_active_rules', { count: activeExpenseRuleCount }) }}
        </button>
      </div>
      <p v-if="activeExpenseRuleCount > 0 && !bundleId" class="mt-3 rounded-md bg-primary-50 p-3 text-sm text-primary-800">
        {{ t('accounting.setup_assistant.active_rules_available', { count: activeExpenseRuleCount }) }}
      </p>
      <p v-if="bundleId" class="mt-3 rounded-md bg-success-50 p-3 text-sm text-success-600">{{ t('accounting.setup_assistant.bundle_ready') }}</p>
      <p v-else-if="!loading && proposals.length === 0" class="mt-4 text-sm text-neutral-500">
        {{ t(run?.completed_at ? 'accounting.setup_assistant.no_new_proposals' : 'accounting.setup_assistant.no_proposals') }}
      </p>
      <div v-if="availableTypes.length" class="mt-5">
        <div class="overflow-x-auto border-b border-neutral-200" role="tablist" :aria-label="t('accounting.setup_assistant.type_tabs')">
          <div class="flex min-w-max gap-1">
            <button v-for="item in availableTypes" :key="item.type" type="button" role="tab"
              :aria-selected="activeType === item.type" class="cursor-pointer whitespace-nowrap border-b-2 px-3 py-2 text-sm transition"
              :class="activeType === item.type ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
              @click="activeType = item.type; sourceFilter = 'all'">
              {{ t(`accounting.setup_assistant.types.${item.type}`) }} ({{ item.count }})
            </button>
          </div>
        </div>
        <div class="mt-3 flex gap-1 overflow-x-auto border-b border-neutral-200" role="tablist" :aria-label="t('accounting.setup_assistant.source_tabs')">
          <button v-for="source in sourceOptions" :key="source" type="button" role="tab"
            :aria-selected="sourceFilter === source" class="cursor-pointer whitespace-nowrap border-b-2 px-3 py-2 text-sm transition"
            :class="sourceFilter === source ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
            @click="sourceFilter = source">
            {{ t(`accounting.setup_assistant.sources.${source}`) }} ({{ sourceCounts[source] ?? 0 }})
          </button>
        </div>
        <div class="divide-y divide-neutral-100 rounded-md border border-neutral-200">
          <div v-for="item in visibleProposals" :key="item.id" class="grid gap-2 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
            <div class="min-w-0">
              <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <span class="truncate font-medium text-neutral-800">{{ proposalTitle(item) }}</span>
                <span class="text-sm text-neutral-600">{{ proposalSummary(item) }}</span>
              </div>
              <div class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-neutral-500">
                <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-neutral-600">{{ t(`accounting.setup_assistant.sources.${proposalSource(item)}`) }}</span>
                <span v-if="item.proposal_json.locale" class="uppercase text-neutral-400">{{ item.proposal_json.locale }}</span>
                <span v-if="item.proposal_json.description_contains">
                  {{ t('accounting.setup_assistant.keyword', { value: item.proposal_json.description_contains }) }}
                </span>
                <span v-if="item.evidence_json.concept" class="text-neutral-400">
                  {{ t('accounting.setup_assistant.concept', { value: conceptLabel(item.evidence_json.concept) }) }}
                </span>
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <span class="whitespace-nowrap text-xs text-neutral-500">{{ Math.round(item.confidence * 100) }} % · {{ item.occurrence_count }}×</span>
              <button v-if="canEdit(item)" type="button" :class="btnOutlineSm('neutral')" @click.stop="openEdit(item)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.edit" /></svg>
                {{ t('accounting.setup_assistant.edit_proposal') }}
              </button>
              <input v-if="isSelectable(item) && !bundleId" type="checkbox" class="h-4 w-4 cursor-pointer"
                :checked="selected.has(item.id)" :aria-label="t('accounting.setup_assistant.select_proposal')" @change="toggle(item.id)" />
            </div>
          </div>
          <p v-if="visibleProposals.length === 0" class="p-4 text-sm text-neutral-500">{{ t('accounting.setup_assistant.no_source_proposals') }}</p>
        </div>
      </div>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="text-lg font-semibold">3. {{ t('accounting.setup_assistant.history_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('accounting.setup_assistant.history_help') }}</p>
      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-sm">{{ t('accounting.setup_assistant.history_date_from') }}<input v-model="historyDateFrom" type="date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" /></label>
        <label class="text-sm">{{ t('accounting.setup_assistant.history_date_to') }}<input v-model="historyDateTo" type="date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" /></label>
      </div>
      <p class="mt-2 text-xs text-neutral-500">{{ t('accounting.setup_assistant.history_range_hint') }}</p>
      <fieldset class="mt-4">
        <legend class="text-sm font-medium text-neutral-700">{{ t('accounting.setup_assistant.history_scope_label') }}</legend>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
          <label class="flex cursor-pointer items-start gap-3 rounded-md border border-neutral-200 p-3 text-sm">
            <input v-model="historyScopeMode" type="radio" value="matched" class="mt-0.5 h-4 w-4" />
            <span><span class="font-medium">{{ t('accounting.setup_assistant.history_scope_matched') }}</span><span class="mt-1 block text-xs text-neutral-500">{{ t('accounting.setup_assistant.history_scope_matched_hint') }}</span></span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 rounded-md border border-neutral-200 p-3 text-sm">
            <input v-model="historyScopeMode" type="radio" value="all" class="mt-0.5 h-4 w-4" />
            <span><span class="font-medium">{{ t('accounting.setup_assistant.history_scope_all') }}</span><span class="mt-1 block text-xs text-neutral-500">{{ t('accounting.setup_assistant.history_scope_all_hint') }}</span></span>
          </label>
        </div>
      </fieldset>
      <p class="mt-2 text-xs text-neutral-500">{{ t('accounting.setup_assistant.bank_history_unchanged') }}</p>
      <div class="mt-4 flex flex-wrap gap-2">
        <button v-if="canPost" :disabled="busy || hasActiveWork || !bundleId || !historyRangeValid" :class="btnOutline('primary')" @click="startReclassification(true)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.search" /></svg>
          {{ t('accounting.setup_assistant.dry_run') }}
        </button>
        <button v-if="canPost" :disabled="busy || hasActiveWork || !dryRunJob || !historyRangeValid" :class="btnFilled('warning')" @click="startReclassification(false)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.cycle" /></svg>
          {{ t('accounting.setup_assistant.apply') }}
        </button>
        <button v-if="canPost && appliedJob?.rollback_snapshot_available && !rollbackExists" :disabled="busy || hasActiveWork" :class="btnOutline('warning')" @click="rollback">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.cycle" /></svg>
          {{ t('accounting.setup_assistant.rollback') }}
        </button>
        <button v-if="canPost && appliedJob?.rollback_snapshot_available" :disabled="busy || hasActiveWork" :class="btnOutline('danger')" @click="deleteSnapshot">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.trash" /></svg>
          {{ t('accounting.setup_assistant.delete_snapshot') }}
        </button>
      </div>
      <ImportJobProgress
        class="mt-4"
        :job="activeReclassificationJob"
        :percent="progress"
        :cancelling="cancelling"
        counts-key="accounting.setup_assistant.job_counts"
        background-hint-key="accounting.setup_assistant.background_hint"
        running-key="accounting.setup_assistant.job_running"
        cancel-key="accounting.setup_assistant.job_cancel"
        cancelling-key="accounting.setup_assistant.job_cancelling"
        @cancel="cancel"
      />
      <p class="mt-3 text-xs text-neutral-500">{{ t('accounting.setup_assistant.closed_period_note') }}</p>
      <p v-if="dryRunItems.length" class="mt-2 text-sm font-medium text-primary-700">
        {{ t('accounting.setup_assistant.dry_run_coverage', {
          percent: dryRunCoverage.toLocaleString(undefined, { maximumFractionDigits: 1 }),
          changed: dryRunChanged,
          total: dryRunItems.length,
        }) }}
      </p>
      <div v-if="dryRunItems.length" class="mt-4 overflow-x-auto rounded-md border border-neutral-200">
        <table class="w-full text-sm">
          <thead><tr class="border-b border-neutral-100 bg-neutral-50 text-left text-xs text-neutral-500">
            <th class="px-3 py-2">{{ t('accounting.setup_assistant.document') }}</th>
            <th class="px-3 py-2">{{ t('accounting.setup_assistant.status') }}</th>
            <th class="px-3 py-2">{{ t('accounting.setup_assistant.before') }}</th>
            <th class="px-3 py-2">{{ t('accounting.setup_assistant.after') }}</th>
          </tr></thead>
          <tbody><tr v-for="item in dryRunItems" :key="item.id" class="border-b border-neutral-50 align-top">
            <td class="px-3 py-2">
              <button v-if="sourceEntryId(item)" type="button" :class="btnOutlineSm('primary')"
                :title="t('accounting.setup_assistant.preview_document')"
                @click.stop="previewEntryId = sourceEntryId(item)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
                #{{ item.purchase_invoice_id }}
              </button>
              <span v-else>#{{ item.purchase_invoice_id }}</span>
            </td>
            <td class="px-3 py-2">
              <span>{{ reclassificationStatus(item.status) }}</span>
              <div v-if="item.error_code" class="mt-1 text-xs text-warning-700">{{ reclassificationError(item.error_code) }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-500">{{ accountList(item.before_json?.lines) || '–' }}</td>
            <td class="px-3 py-2 text-xs text-neutral-500">{{ accountList(item.after_json?.lines) || '–' }}</td>
          </tr></tbody>
        </table>
      </div>
      <div v-if="jobs.length" class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="border-b border-neutral-100 text-left text-xs text-neutral-500"><th class="py-2">{{ t('accounting.setup_assistant.job') }}</th><th>{{ t('accounting.setup_assistant.status') }}</th><th class="text-right">{{ t('accounting.setup_assistant.changed') }}</th><th class="text-right">{{ t('accounting.setup_assistant.skipped') }}</th><th class="text-right">{{ t('accounting.setup_assistant.failed_count') }}</th></tr></thead>
          <tbody><tr v-for="job in jobs" :key="job.id" class="border-b border-neutral-50"><td class="py-2">#{{ job.id }} <span class="text-xs text-neutral-400">{{ job.params?.dry_run ? t('accounting.setup_assistant.dry_badge') : '' }}</span></td><td>{{ jobStatus(job.status) }}</td><td class="text-right">{{ job.created_count }}</td><td class="text-right">{{ job.skipped_count }}</td><td class="text-right">{{ job.failed_count }}</td></tr></tbody>
        </table>
      </div>
    </section>

    <Modal v-if="editingProposal" :title="t('accounting.setup_assistant.edit_title')" width-class="max-w-xl" @close="editingProposal = null">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <label v-if="editingProposal.proposal_type === 'chart_account'" class="flex cursor-pointer items-start gap-3 rounded-md border border-neutral-200 p-3 text-sm">
          <input v-model="editForm.create" type="checkbox" class="mt-0.5 h-4 w-4" />
          <span>
            <span class="font-medium">{{ t('accounting.setup_assistant.fields.create_analytic') }}</span>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('accounting.setup_assistant.fields.create_analytic_hint') }}</span>
          </span>
        </label>
        <label v-if="(editingProposal.proposal_type === 'chart_account' && editForm.create) || editingProposal.proposal_type === 'expense_rule' || editingProposal.proposal_type === 'bank_rule'" class="block text-sm">
          {{ t('accounting.setup_assistant.fields.name') }}
          <input v-model="editForm.name" required maxlength="160" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
        </label>
        <label v-if="editingProposal.proposal_type === 'chart_account' && !editForm.create" class="block text-sm">
          {{ t('accounting.setup_assistant.fields.replacement_account') }}
          <SearchableSelect :model-value="editForm.replacement_account_code || null" :options="replacementAccountOptions"
            class="mt-1" :clearable="false" required teleport input-class="font-mono"
            @update:model-value="value => editForm.replacement_account_code = value || ''" />
        </label>
        <template v-if="editingProposal.proposal_type === 'expense_rule'">
          <label class="block text-sm">
            {{ t('accounting.setup_assistant.fields.keyword') }}
            <input v-model="editForm.description_contains" required maxlength="190" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <label class="block text-sm">
            {{ t('accounting.setup_assistant.fields.expense_kind') }}
            <select v-model="editForm.expense_kind" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option v-for="kind in ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset']" :key="kind" :value="kind">
                {{ t(`accounting.setup_assistant.expense_kinds.${kind}`) }}
              </option>
            </select>
          </label>
          <label class="block text-sm">
            {{ t('accounting.setup_assistant.fields.target_account') }}
            <SearchableSelect :model-value="editForm.target_account_code || null" :options="expenseAccountOptions"
              class="mt-1" :clearable="false" required teleport input-class="font-mono"
              @update:model-value="value => editForm.target_account_code = value || ''" />
          </label>
        </template>
        <label v-if="editingProposal.proposal_type === 'posting_rule'" class="block text-sm">
          {{ t('accounting.setup_assistant.fields.description') }}
          <input v-model="editForm.description" required maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
        </label>
        <label v-if="editingProposal.proposal_type === 'bank_rule'" class="block text-sm">
          {{ t('accounting.setup_assistant.fields.bank_message') }}
          <input v-model="editForm.message_contains" maxlength="190" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          <span class="mt-1 block text-xs text-neutral-500">{{ t('accounting.setup_assistant.fields.bank_message_hint') }}</span>
        </label>
        <div v-if="editingProposal.proposal_type === 'posting_rule' || editingProposal.proposal_type === 'bank_rule'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label class="block text-sm">
            {{ t('accounting.setup_assistant.fields.debit_account') }}
            <SearchableSelect :model-value="editForm.debit_account_code || null"
              :options="editingProposal.proposal_type === 'posting_rule' ? analyticAccountOptions : allAccountOptions"
              class="mt-1" :clearable="false" required teleport input-class="font-mono"
              @update:model-value="value => editForm.debit_account_code = value || ''" />
          </label>
          <label class="block text-sm">
            {{ t('accounting.setup_assistant.fields.credit_account') }}
            <SearchableSelect :model-value="editForm.credit_account_code || null" :options="allAccountOptions"
              class="mt-1" :clearable="false" required teleport input-class="font-mono"
              @update:model-value="value => editForm.credit_account_code = value || ''" />
          </label>
        </div>
        <p v-if="editingProposal.proposal_type === 'chart_account'" class="rounded-md bg-neutral-50 p-3 text-xs text-neutral-600">
          {{ t(editForm.create ? 'accounting.setup_assistant.fields.account_code_locked' : 'accounting.setup_assistant.fields.account_replacement_help', {
            code: editingProposal.proposal_json.account_code,
            parent: editingProposal.proposal_json.parent_account_code,
            replacement: editForm.replacement_account_code || '?',
            count: editingDependencyCount,
          }) }}
        </p>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="editingProposal = null">
            {{ t('accounting.setup_assistant.cancel_edit') }}
          </button>
          <button type="button" :disabled="editSaving" :class="btnFilled('success')" class="whitespace-nowrap" @click="saveEdit">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check" /></svg>
            {{ t('accounting.setup_assistant.save_edit') }}
          </button>
        </div>
      </template>
    </Modal>

    <JournalSourceDrawer v-if="previewEntryId" :entry-id="previewEntryId" @close="previewEntryId = null" />
  </div>
</template>

<script setup lang="ts">
/**
 * Hromadné přeúčtování zaúčtovaných zápisů v deníku (účet X → Y podle filtru).
 *
 * Sesterská stránka k Doúčtování: stejná kostra (úloha na pozadí, průběh, protokol,
 * poslední běhy). Ostrý běh jde spustit jen nad dokončeným během nanečisto a přeúčtuje
 * přesně jeho výsledek bez ručně vyřazených zápisů. O tom, kdo smí spouštět, rozhoduje
 * server polem `can_run`.
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  journalRebookApi,
  JOURNAL_REBOOK_PER_PAGE,
  type JournalRebookCount,
  type JournalRebookItem,
  type JournalRebookItemStatus,
  type JournalRebookItemsPage,
  type JournalRebookJob,
  type JournalRebookMode,
  type JournalRebookStatus,
} from '@/api/journalRebook'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import {
  REBOOK_SOURCE_TYPES,
  buildRebookFilter,
  changedLines,
  isRebookFormValid,
  rebookReasonText,
  type JournalRebookForm,
} from '@/utils/journalRebook'

const { t, locale } = useI18n()
const route = useRoute()
const toast = useToast()

const state = ref<JournalRebookStatus | null>(null)
const running = ref<JournalRebookJob | null>(null)
const loading = ref(true)
const starting = ref(false)
const cancelling = ref(false)
const error = ref('')
const accounts = ref<ChartAccount[]>([])

let timer: ReturnType<typeof setTimeout> | null = null

function queryString(key: string): string {
  const v = route.query[key]
  return typeof v === 'string' ? v : ''
}

const year = new Date().getFullYear()
const form = reactive<JournalRebookForm>({
  from_account: queryString('from_account'),
  to_account: queryString('to_account'),
  side: 'both',
  date_from: queryString('date_from') || `${year}-01-01`,
  date_to: queryString('date_to') || `${year}-12-31`,
  source_types: [...REBOOK_SOURCE_TYPES],
  text: '',
  amount_min: '',
  amount_max: '',
  mode: 'rewrite',
})

const canRun = computed(() => !!state.value?.can_run)
const formValid = computed(() => isRebookFormValid(form))
const isRunning = computed(() =>
  running.value?.status === 'queued' || running.value?.status === 'running')
const percent = computed(() => {
  const j = running.value
  if (!j || !j.total_items) return null
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
})

// ── Živý počet ─────────────────────────────────────────────────────────────
const count = ref<JournalRebookCount | null>(null)
const countLoading = ref(false)
const countError = ref('')
let countTimer: ReturnType<typeof setTimeout> | null = null
let countSeq = 0

const overLimit = computed(() => !!count.value && count.value.matching > count.value.limit)

function scheduleCount() {
  if (countTimer !== null) clearTimeout(countTimer)
  countTimer = null
  countError.value = ''
  if (!formValid.value) {
    count.value = null
    countLoading.value = false
    return
  }
  countLoading.value = true
  const seq = ++countSeq
  countTimer = setTimeout(async () => {
    countTimer = null
    try {
      const res = await journalRebookApi.count(buildRebookFilter(form))
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
  () => [form.from_account, form.to_account, form.side, form.date_from, form.date_to,
    form.source_types.join(','), form.text, form.amount_min, form.amount_max],
  scheduleCount,
)

// ── Načtení a polling ──────────────────────────────────────────────────────
function stopPolling() {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    state.value = await journalRebookApi.status()
    const active = state.value.jobs.find(j => j.status === 'queued' || j.status === 'running')
    if (active) {
      running.value = active
      poll(active.id)
    } else if (!selected.value) {
      const latest = state.value.jobs[0]
      if (latest && latest.kind === 'dry_run' && latest.can_apply) selectJob(latest)
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function doneMessage(j: JournalRebookJob): string {
  if (j.kind === 'dry_run') return t('accounting.journal_rebook.done_dry', { n: j.changed_count })
  if (j.kind === 'rollback') return t('accounting.journal_rebook.done_rollback', { n: j.changed_count })
  return t('accounting.journal_rebook.done_apply', { n: j.changed_count })
}

function poll(jobId: number) {
  // Úloha běží dál i bez nás, takže výpadek jednoho dotazu není důvod přestat se ptát.
  let failures = 0
  const tick = async () => {
    try {
      const j = await journalRebookApi.job(jobId)
      failures = 0
      running.value = j
      if (j.status === 'queued' || j.status === 'running') {
        timer = setTimeout(tick, 1500)
        return
      }
      timer = null
      cancelling.value = false
      if (j.status === 'failed') {
        toast.error(j.last_error || t('accounting.journal_rebook.failed'))
      } else if (j.status === 'cancelled') {
        toast.warning(t('accounting.journal_rebook.cancelled'))
      } else {
        toast.success(doneMessage(j))
      }
      state.value = await journalRebookApi.status()
      if (j.status !== 'failed') selectJob(state.value.jobs.find(x => x.id === j.id) ?? j)
    } catch (e) {
      failures++
      if (failures < 5) {
        timer = setTimeout(tick, 3000)
      } else {
        timer = null
        error.value = apiErrorMessage(e)
      }
    }
  }
  timer = null
  void tick()
}

async function startJob(run: () => Promise<{ job_id: number }>) {
  starting.value = true
  error.value = ''
  try {
    const { job_id } = await run()
    running.value = null
    poll(job_id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    starting.value = false
  }
}

function startDryRun() {
  void startJob(() => journalRebookApi.start({ ...buildRebookFilter(form), mode: form.mode, dry_run: true }))
}

async function cancel() {
  const id = running.value?.id
  if (!id || cancelling.value) return
  cancelling.value = true
  try {
    await journalRebookApi.cancel(id)
  } catch (e) {
    cancelling.value = false
    toast.error(apiErrorMessage(e))
  }
}

// ── Výsledek vybraného běhu ────────────────────────────────────────────────
const selected = ref<JournalRebookJob | null>(null)
const activeTab = ref<JournalRebookItemStatus>('would_change')
const page = ref(1)
const itemsPage = ref<JournalRebookItemsPage | null>(null)
const itemsLoading = ref(false)
const itemsError = ref('')
const counts = ref<Partial<Record<JournalRebookItemStatus, number>>>({})
const excluded = ref<Set<number>>(new Set())
let itemsSeq = 0

const tabsByKind: Record<JournalRebookJob['kind'], JournalRebookItemStatus[]> = {
  dry_run: ['would_change', 'skipped', 'unchanged'],
  apply: ['applied', 'failed', 'skipped', 'excluded', 'unchanged'],
  rollback: ['applied', 'failed', 'skipped', 'unchanged'],
}

const tabs = computed<JournalRebookItemStatus[]>(() => {
  const job = selected.value
  if (!job) return []
  const all = tabsByKind[job.kind]
  return all.filter((s, i) => i === 0 || s === activeTab.value || (counts.value[s] ?? 0) > 0)
})

const selectedFinished = computed(() =>
  !!selected.value && selected.value.status !== 'queued' && selected.value.status !== 'running')
const canExclude = computed(() =>
  selected.value?.kind === 'dry_run' && selected.value.can_apply && activeTab.value === 'would_change')
const wouldChangeCount = computed(() => counts.value.would_change ?? selected.value?.changed_count ?? 0)
const applyCount = computed(() => Math.max(0, wouldChangeCount.value - excluded.value.size))

function selectJob(job: JournalRebookJob) {
  if (selected.value?.id !== job.id) excluded.value = new Set()
  selected.value = job
  counts.value = {}
  activeTab.value = tabsByKind[job.kind][0]
  page.value = 1
  void loadItems()
}

async function loadItems() {
  const job = selected.value
  if (!job) return
  const seq = ++itemsSeq
  itemsLoading.value = true
  itemsError.value = ''
  try {
    const res = await journalRebookApi.items(job.id, activeTab.value, page.value)
    if (seq !== itemsSeq) return
    itemsPage.value = res
    counts.value = res.counts
  } catch (e) {
    if (seq !== itemsSeq) return
    itemsPage.value = null
    itemsError.value = apiErrorMessage(e)
  } finally {
    if (seq === itemsSeq) itemsLoading.value = false
  }
}

function setTab(tab: JournalRebookItemStatus) {
  activeTab.value = tab
  page.value = 1
  void loadItems()
}

function setPage(p: number) {
  page.value = p
  void loadItems()
}

function isIncluded(item: JournalRebookItem): boolean {
  return !excluded.value.has(item.entry_id)
}

function toggleItem(item: JournalRebookItem) {
  const next = new Set(excluded.value)
  if (next.has(item.entry_id)) next.delete(item.entry_id)
  else next.add(item.entry_id)
  excluded.value = next
}

const pageAllIncluded = computed(() =>
  (itemsPage.value?.items ?? []).every(isIncluded))

function togglePage() {
  const next = new Set(excluded.value)
  const include = !pageAllIncluded.value
  for (const item of itemsPage.value?.items ?? []) {
    if (include) next.delete(item.entry_id)
    else next.add(item.entry_id)
  }
  excluded.value = next
}

// ── Potvrzení ostrého běhu a vrácení ───────────────────────────────────────
const confirmApply = ref(false)
const rollbackTarget = ref<JournalRebookJob | null>(null)

function modeLabel(mode: JournalRebookMode): string {
  return t('accounting.journal_rebook.mode.' + mode)
}

const applyMessage = computed(() => {
  const job = selected.value
  if (!job) return ''
  return t('accounting.journal_rebook.confirm_apply', {
    n: applyCount.value, from: job.params.from_account, to: job.params.to_account, mode: modeLabel(job.params.mode),
  })
})

function runApply() {
  const job = selected.value
  confirmApply.value = false
  if (!job) return
  void startJob(() => journalRebookApi.start({
    dry_run: false, dry_run_job_id: job.id, excluded_entry_ids: [...excluded.value],
  }))
}

function runRollback() {
  const job = rollbackTarget.value
  rollbackTarget.value = null
  if (!job) return
  void startJob(() => journalRebookApi.rollback(job.id))
}

const canApplySelected = computed(() => {
  const job = selected.value
  return !!job && job.kind === 'dry_run' && job.can_apply
    && (job.status === 'completed' || job.status === 'completed_with_warnings')
})

const actions = computed<ActionItem[]>(() => [
  {
    key: 'apply', label: t('accounting.journal_rebook.action_apply'), icon: 'check',
    tier: 'primary', variant: 'primary',
    show: canRun.value,
    disabled: loading.value || starting.value || isRunning.value || !canApplySelected.value || applyCount.value === 0,
    disabledReason: !canApplySelected.value ? t('accounting.journal_rebook.action_apply_reason') : undefined,
    loading: starting.value,
    title: t('accounting.journal_rebook.action_apply_hint'),
    run: () => { confirmApply.value = true },
  },
  {
    key: 'dry', label: t('accounting.journal_rebook.action_dry'), icon: 'search',
    tier: 'secondary', variant: 'neutral',
    show: canRun.value,
    disabled: loading.value || starting.value || isRunning.value || !formValid.value || overLimit.value,
    title: t('accounting.journal_rebook.action_dry_hint'),
    run: startDryRun,
  },
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'overflow', variant: 'neutral',
    show: true, disabled: loading.value, loading: loading.value, run: load,
  },
])

// ── Formátování ────────────────────────────────────────────────────────────
function fmtDateTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function statusLabel(status: JournalRebookJob['status']): string {
  return t('accounting.journal_rebook.status.' + status)
}

function kindLabel(kind: JournalRebookJob['kind']): string {
  return t('accounting.journal_rebook.kind.' + kind)
}

function tabLabel(tab: JournalRebookItemStatus): string {
  return t('accounting.journal_rebook.tab.' + tab)
}

function strategyLabel(item: JournalRebookItem): string {
  if (!item.strategy) return '—'
  const base = t('accounting.journal_rebook.strategy.' + item.strategy)
  return item.date_shifted && item.target_date
    ? `${base} ${t('accounting.journal_rebook.strategy_date', { date: formatDate(item.target_date) })}`
    : base
}

function reasonText(item: JournalRebookItem): string {
  return rebookReasonText(item, key => t(key))
}

const showReason = computed(() => activeTab.value !== 'would_change' && activeTab.value !== 'applied')

const resultTitle = computed(() => {
  const kind = selected.value?.kind
  return t(kind === 'apply' ? 'accounting.journal_rebook.result_title_apply'
    : kind === 'rollback' ? 'accounting.journal_rebook.result_title_rollback'
      : 'accounting.journal_rebook.result_title_dry')
})

onMounted(() => {
  void load()
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
  scheduleCount()
})
onUnmounted(() => {
  stopPolling()
  if (countTimer !== null) clearTimeout(countTimer)
})
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.journal_rebook.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.journal_rebook.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.journal_rebook.explainer_title') }}</p>
      <p>{{ t('accounting.journal_rebook.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading && !state" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <template v-else>
      <!-- Co přeúčtovat -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4" data-test="rebook-form">
        <h2 class="font-medium text-neutral-900 mb-3">{{ t('accounting.journal_rebook.form_title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="rebook-from">{{ t('accounting.journal_rebook.from_account') }}</label>
            <ChartAccountSelect v-model="form.from_account" :accounts="accounts" input-id="rebook-from" :disabled="!canRun" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="rebook-to">{{ t('accounting.journal_rebook.to_account') }}</label>
            <ChartAccountSelect v-model="form.to_account" :accounts="accounts" input-id="rebook-to" :disabled="!canRun" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="rebook-side">{{ t('accounting.journal_rebook.side') }}</label>
            <select id="rebook-side" v-model="form.side" :disabled="!canRun" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="both">{{ t('accounting.journal_rebook.side_both') }}</option>
              <option value="debit">{{ t('accounting.journal_rebook.side_debit') }}</option>
              <option value="credit">{{ t('accounting.journal_rebook.side_credit') }}</option>
            </select>
          </div>
          <div>
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal_rebook.period') }}</span>
            <div class="flex items-center gap-2">
              <DateInput v-model="form.date_from" required :disabled="!canRun" :aria-label="t('accounting.journal_rebook.date_from')"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
              <span class="text-neutral-400">-</span>
              <DateInput v-model="form.date_to" required :disabled="!canRun" :aria-label="t('accounting.journal_rebook.date_to')"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="sm:col-span-2">
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal_rebook.sources') }}</span>
            <div class="flex flex-wrap gap-x-5 gap-y-1">
              <label v-for="s in REBOOK_SOURCE_TYPES" :key="s" class="inline-flex items-center gap-2 whitespace-nowrap cursor-pointer">
                <input v-model="form.source_types" type="checkbox" :value="s" :disabled="!canRun" class="h-4 w-4" />
                {{ t('accounting.journal_rebook.source.' + s) }}
              </label>
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1" for="rebook-text">{{ t('accounting.journal_rebook.text') }}</label>
            <input id="rebook-text" v-model="form.text" type="text" :disabled="!canRun" :placeholder="t('accounting.journal_rebook.text_placeholder')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal_rebook.amount') }}</span>
            <div class="flex items-center gap-2">
              <input v-model="form.amount_min" type="text" inputmode="decimal" :disabled="!canRun" :aria-label="t('accounting.journal_rebook.amount_min')"
                :placeholder="t('accounting.journal_rebook.amount_min')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
              <span class="text-neutral-400">-</span>
              <input v-model="form.amount_max" type="text" inputmode="decimal" :disabled="!canRun" :aria-label="t('accounting.journal_rebook.amount_max')"
                :placeholder="t('accounting.journal_rebook.amount_max')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface" />
            </div>
          </div>
          <div class="sm:col-span-2">
            <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal_rebook.mode_label') }}</span>
            <div class="space-y-1">
              <label class="flex items-start gap-2 cursor-pointer">
                <input v-model="form.mode" type="radio" value="rewrite" :disabled="!canRun" class="mt-0.5 h-4 w-4" />
                <span>{{ t('accounting.journal_rebook.mode_rewrite_option') }}</span>
              </label>
              <label class="flex items-start gap-2 cursor-pointer">
                <input v-model="form.mode" type="radio" value="rewrite_or_reverse" :disabled="!canRun" class="mt-0.5 h-4 w-4" />
                <span>{{ t('accounting.journal_rebook.mode_reverse_option') }}</span>
              </label>
            </div>
          </div>
        </div>

        <div class="mt-4 text-sm" data-test="rebook-count">
          <p v-if="!formValid" class="text-neutral-500">{{ t('accounting.journal_rebook.form_incomplete') }}</p>
          <p v-else-if="countError" class="text-danger-500">{{ countError }}</p>
          <p v-else-if="countLoading && !count" class="text-neutral-400">{{ t('common.loading') }}…</p>
          <template v-else-if="count">
            <p class="font-medium" :class="count.matching > 0 ? 'text-neutral-900' : 'text-neutral-500'">
              {{ t('accounting.journal_rebook.matching', { n: count.matching }) }}
              <span class="font-normal text-neutral-500">
                ({{ REBOOK_SOURCE_TYPES.map(s => t('accounting.journal_rebook.source.' + s) + ': ' + (count?.by_source[s] ?? 0)).join(' · ') }})
              </span>
            </p>
            <p v-if="overLimit" class="mt-1 text-danger-500" data-test="rebook-over-limit">{{ t('accounting.journal_rebook.over_limit', { limit: count.limit }) }}</p>
            <p v-if="count.unsupported > 0" class="mt-1 text-warning-600">{{ t('accounting.journal_rebook.unsupported', { n: count.unsupported }) }}</p>
          </template>
        </div>
      </div>

      <!-- Průběh -->
      <div v-if="running" class="rounded-lg border border-primary-200 bg-primary-50/50 px-4 py-4 mb-4 space-y-2">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="text-sm font-medium text-primary-700">
            {{ statusLabel(running.status) }}
            <span class="ml-2 text-xs text-neutral-500">({{ kindLabel(running.kind) }})</span>
          </div>
          <button
            v-if="isRunning"
            type="button"
            class="cursor-pointer text-sm text-danger-500 hover:underline whitespace-nowrap"
            :disabled="cancelling"
            @click="cancel"
          >
            {{ cancelling ? t('accounting.journal_rebook.cancelling') : t('accounting.journal_rebook.cancel') }}
          </button>
        </div>

        <div class="h-2 rounded-full bg-primary-100 overflow-hidden">
          <div
            class="h-full bg-primary-500 transition-all duration-300"
            :class="percent === null ? 'animate-pulse w-1/3' : ''"
            :style="percent === null ? undefined : { width: percent + '%' }"
          ></div>
        </div>

        <div class="flex justify-between text-xs text-neutral-600 flex-wrap gap-x-4">
          <span v-if="running.total_items">{{ running.processed }} / {{ running.total_items }}<span v-if="percent !== null"> ({{ percent }} %)</span></span>
          <span>{{ t('accounting.journal_rebook.counts', {
            changed: running.changed_count, skipped: running.skipped_count, failed: running.failed_count,
          }) }}</span>
        </div>

        <p class="text-xs text-neutral-500">{{ t('accounting.journal_rebook.background_hint') }}</p>

        <details v-if="running.log_text" class="text-xs">
          <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('accounting.journal_rebook.log') }}</summary>
          <pre class="mt-2 max-h-72 overflow-auto bg-neutral-900 text-neutral-100 rounded p-3 text-[11px] leading-relaxed">{{ running.log_text }}</pre>
        </details>
      </div>

      <!-- Výsledek vybraného běhu -->
      <div v-if="selected && selectedFinished" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4" data-test="rebook-result">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
          <h2 class="font-medium text-neutral-900">{{ resultTitle }}</h2>
          <span class="text-xs text-neutral-500 font-mono">
            {{ selected.params.from_account }} → {{ selected.params.to_account }} · {{ modeLabel(selected.params.mode) }}
          </span>
        </div>
        <p class="text-sm text-neutral-700 mb-3">
          {{ t(selected.kind === 'dry_run' ? 'accounting.journal_rebook.summary' : 'accounting.journal_rebook.summary_done', {
            changed: selected.kind === 'dry_run' ? (counts.would_change ?? selected.changed_count) : selected.changed_count,
            skipped: counts.skipped ?? selected.skipped_count,
            unchanged: counts.unchanged ?? 0,
          }) }}
          <span v-if="canExclude || (selected.kind === 'dry_run' && excluded.size > 0)" class="ml-2 text-neutral-500" data-test="rebook-excluded">
            · {{ t('accounting.journal_rebook.excluded_manually', { n: excluded.size }) }}
          </span>
        </p>

        <div class="overflow-x-auto border-b border-neutral-200" role="tablist" :aria-label="resultTitle">
          <div class="flex min-w-max gap-1">
            <button v-for="tab in tabs" :key="tab" type="button" role="tab"
              :aria-selected="activeTab === tab" class="cursor-pointer whitespace-nowrap border-b-2 px-3 py-2 text-sm transition"
              :class="activeTab === tab ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
              @click="setTab(tab)">
              {{ tabLabel(tab) }} ({{ counts[tab] ?? 0 }})
            </button>
          </div>
        </div>

        <div v-if="itemsError" class="mt-3 text-sm text-danger-500">{{ itemsError }}</div>
        <div v-else-if="itemsLoading && !itemsPage" class="py-6 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
        <p v-else-if="itemsPage && itemsPage.items.length === 0" class="py-6 text-center text-sm text-neutral-400">{{ t('accounting.journal_rebook.no_items') }}</p>
        <template v-else-if="itemsPage">
          <!-- Desktop -->
          <div class="hidden md:block overflow-x-auto" :class="itemsLoading ? 'opacity-60' : ''">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th v-if="canExclude" class="py-2 pr-2 w-6">
                    <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="pageAllIncluded"
                      :aria-label="t('accounting.journal_rebook.include_page')" @change="togglePage" />
                  </th>
                  <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_document') }}</th>
                  <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_date') }}</th>
                  <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_description') }}</th>
                  <th class="py-2 pr-3 text-right">{{ t('accounting.journal_rebook.col_amount') }}</th>
                  <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_change') }}</th>
                  <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_strategy') }}</th>
                  <th v-if="showReason" class="py-2">{{ t('accounting.journal_rebook.col_reason') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in itemsPage.items" :key="item.id" class="border-b border-neutral-50 last:border-0 align-top"
                  :class="canExclude && !isIncluded(item) ? 'opacity-50' : ''">
                  <td v-if="canExclude" class="py-2 pr-2">
                    <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="isIncluded(item)"
                      :aria-label="item.document_no || String(item.entry_id)" @change="toggleItem(item)" />
                  </td>
                  <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ item.document_no || '—' }}</td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ formatDate(item.entry_date) }}</td>
                  <td class="py-2 pr-3 max-w-64 truncate" :title="item.description || undefined">{{ item.description || '—' }}</td>
                  <td class="py-2 pr-3 text-right font-mono whitespace-nowrap">{{ formatMoney(item.amount) }}</td>
                  <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">
                    <div v-for="(c, i) in changedLines(item)" :key="i">{{ c.from }} → {{ c.to }}</div>
                    <span v-if="changedLines(item).length === 0" class="text-neutral-400">—</span>
                  </td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ strategyLabel(item) }}</td>
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
                    :aria-label="item.document_no || String(item.entry_id)" @change="toggleItem(item)" />
                  <span class="font-mono text-xs text-neutral-600">{{ item.document_no || '—' }}</span>
                  <span class="text-xs text-neutral-500">{{ formatDate(item.entry_date) }}</span>
                </label>
                <span class="font-mono font-semibold whitespace-nowrap">{{ formatMoney(item.amount) }}</span>
              </div>
              <div v-if="item.description" class="text-neutral-600 truncate">{{ item.description }}</div>
              <div class="font-mono text-xs">
                <span v-for="(c, i) in changedLines(item)" :key="i" class="mr-3 whitespace-nowrap">{{ c.from }} → {{ c.to }}</span>
                <span class="text-neutral-500 font-sans">{{ strategyLabel(item) }}</span>
              </div>
              <div v-if="showReason" class="text-xs text-neutral-600">{{ reasonText(item) }}</div>
            </div>
          </div>

          <PaginationBar class="mt-3" :page="page" :per-page="JOURNAL_REBOOK_PER_PAGE" :total="itemsPage.total" @update:page="setPage" />
        </template>
      </div>

      <!-- Historie -->
      <div v-if="state && state.jobs.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <h2 class="font-medium text-neutral-900 mb-3">{{ t('accounting.journal_rebook.history_title') }}</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_started') }}</th>
                <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_kind') }}</th>
                <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_account') }}</th>
                <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_mode') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('accounting.journal_rebook.col_changed') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('accounting.journal_rebook.col_skipped') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('accounting.journal_rebook.col_failed') }}</th>
                <th class="py-2 pr-3">{{ t('accounting.journal_rebook.col_status') }}</th>
                <th class="py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="j in state.jobs" :key="j.id" class="border-b border-neutral-50 last:border-0"
                :class="selected?.id === j.id ? 'bg-primary-50/60' : ''">
                <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDateTime(j.created_at) }}</td>
                <td class="py-2 pr-3 whitespace-nowrap">{{ kindLabel(j.kind) }}</td>
                <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ j.params.from_account }} → {{ j.params.to_account }}</td>
                <td class="py-2 pr-3 whitespace-nowrap">{{ modeLabel(j.params.mode) }}</td>
                <td class="py-2 pr-3 text-right">{{ j.changed_count }}</td>
                <td class="py-2 pr-3 text-right">{{ j.skipped_count }}</td>
                <td class="py-2 pr-3 text-right" :class="j.failed_count > 0 ? 'text-danger-500 font-medium' : ''">{{ j.failed_count }}</td>
                <td class="py-2 pr-3 whitespace-nowrap">
                  {{ statusLabel(j.status) }}
                  <span v-if="j.rolled_back_by_job_id" class="ml-1 inline-flex items-center rounded-full bg-warning-50 border border-warning-200 px-2 py-0.5 text-[11px] font-medium text-warning-700"
                    data-test="rebook-rolled-back">{{ t('accounting.journal_rebook.rolled_back_badge') }}</span>
                </td>
                <td class="py-2">
                  <div class="flex flex-wrap justify-end gap-1.5">
                    <button v-if="j.status !== 'queued' && j.status !== 'running'" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap"
                      @click="selectJob(j)">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                      {{ t('accounting.journal_rebook.action_result') }}
                    </button>
                    <button v-if="canRun && j.kind === 'apply' && j.can_rollback" type="button" :class="btnOutlineSm('warning')" class="whitespace-nowrap"
                      :disabled="starting || isRunning" data-test="rebook-rollback" @click="rollbackTarget = j">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                      {{ t('accounting.journal_rebook.action_rollback') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <ConfirmDialog
      v-if="confirmApply"
      :title="t('accounting.journal_rebook.confirm_apply_title')"
      :message="applyMessage"
      :confirm-label="t('accounting.journal_rebook.action_apply')"
      :busy="starting"
      @confirm="runApply"
      @cancel="confirmApply = false"
    />
    <ConfirmDialog
      v-if="rollbackTarget"
      :title="t('accounting.journal_rebook.confirm_rollback_title')"
      :message="t('accounting.journal_rebook.confirm_rollback', { n: rollbackTarget.changed_count })"
      :confirm-label="t('accounting.journal_rebook.action_rollback')"
      variant="warning"
      :busy="starting"
      @confirm="runRollback"
      @cancel="rollbackTarget = null"
    />
  </div>
</template>

import { computed, ref, shallowRef, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import type { JournalRebookItemStatus, JournalRebookJobKind, JournalRebookJobStatus } from '@/api/journalRebook'

/**
 * Společná kostra hromadných účetních úloh (přeúčtování, bankovní pohyby): načtení stavu,
 * polling běžící úlohy, výsledek vybraného běhu po záložkách a ruční vyřazení položek
 * z běhu nanečisto. Formulář, počet a texty akcí si drží každá stránka sama.
 *
 * Texty hlášek se berou z `{i18nPrefix}.failed|cancelled|done_dry|done_apply|done_rollback`.
 */
export interface BulkJobLike {
  id: number
  kind: JournalRebookJobKind
  status: JournalRebookJobStatus
  total_items: number | null
  processed: number
  changed_count: number
  skipped_count: number
  failed_count: number
  last_error: string | null
  can_apply: boolean
  can_rollback: boolean
  log_text?: string | null
}

export interface BulkItemsPage<I> {
  items: I[]
  total: number
  counts: Partial<Record<JournalRebookItemStatus, number>>
}

export interface BulkJobsApi<J extends BulkJobLike, S extends { jobs: J[] }, I> {
  status(): Promise<S>
  job(id: number): Promise<J>
  cancel(id: number): Promise<void>
  items(id: number, status: JournalRebookItemStatus | null, page: number): Promise<BulkItemsPage<I>>
}

export const BULK_TABS_BY_KIND: Record<JournalRebookJobKind, JournalRebookItemStatus[]> = {
  dry_run: ['would_change', 'skipped', 'unchanged'],
  apply: ['applied', 'failed', 'skipped', 'excluded', 'unchanged'],
  rollback: ['applied', 'failed', 'skipped', 'unchanged'],
}

export function useBulkJobs<J extends BulkJobLike, S extends { jobs: J[] }, I>(options: {
  api: BulkJobsApi<J, S, I>
  /** Klíč položky pro ruční vyřazení (id zápisu, id pohybu). */
  itemKey: (item: I) => number
  i18nPrefix: string
}) {
  const { t } = useI18n()
  const toast = useToast()
  const { api, itemKey, i18nPrefix } = options

  const state = shallowRef<S | null>(null) as Ref<S | null>
  const running = shallowRef<J | null>(null) as Ref<J | null>
  const loading = ref(true)
  const starting = ref(false)
  const cancelling = ref(false)
  const error = ref('')

  let timer: ReturnType<typeof setTimeout> | null = null

  const isRunning = computed(() =>
    running.value?.status === 'queued' || running.value?.status === 'running')
  const percent = computed(() => {
    const j = running.value
    if (!j || !j.total_items) return null
    return Math.min(100, Math.round((j.processed / j.total_items) * 100))
  })

  function stopPolling() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  // ── Výsledek vybraného běhu ──────────────────────────────────────────────
  const selected = shallowRef<J | null>(null) as Ref<J | null>
  const activeTab = ref<JournalRebookItemStatus>('would_change')
  const page = ref(1)
  const itemsPage = shallowRef<BulkItemsPage<I> | null>(null) as Ref<BulkItemsPage<I> | null>
  const itemsLoading = ref(false)
  const itemsError = ref('')
  const counts = ref<Partial<Record<JournalRebookItemStatus, number>>>({})
  const excluded = ref<Set<number>>(new Set())
  let itemsSeq = 0

  const tabs = computed<JournalRebookItemStatus[]>(() => {
    const job = selected.value
    if (!job) return []
    const all = BULK_TABS_BY_KIND[job.kind]
    return all.filter((s, i) => i === 0 || s === activeTab.value || (counts.value[s] ?? 0) > 0)
  })

  const selectedFinished = computed(() =>
    !!selected.value && selected.value.status !== 'queued' && selected.value.status !== 'running')
  const canExclude = computed(() =>
    selected.value?.kind === 'dry_run' && selected.value.can_apply && activeTab.value === 'would_change')
  const wouldChangeCount = computed(() => counts.value.would_change ?? selected.value?.changed_count ?? 0)
  const applyCount = computed(() => Math.max(0, wouldChangeCount.value - excluded.value.size))
  const canApplySelected = computed(() => {
    const job = selected.value
    return !!job && job.kind === 'dry_run' && job.can_apply
      && (job.status === 'completed' || job.status === 'completed_with_warnings')
  })

  async function loadItems() {
    const job = selected.value
    if (!job) return
    const seq = ++itemsSeq
    itemsLoading.value = true
    itemsError.value = ''
    try {
      const res = await api.items(job.id, activeTab.value, page.value)
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

  function selectJob(job: J) {
    if (selected.value?.id !== job.id) excluded.value = new Set()
    selected.value = job
    counts.value = {}
    activeTab.value = BULK_TABS_BY_KIND[job.kind][0]
    page.value = 1
    void loadItems()
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

  function isIncluded(item: I): boolean {
    return !excluded.value.has(itemKey(item))
  }

  function toggleItem(item: I) {
    const next = new Set(excluded.value)
    const key = itemKey(item)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    excluded.value = next
  }

  const pageAllIncluded = computed(() =>
    (itemsPage.value?.items ?? []).every(isIncluded))

  function togglePage() {
    const next = new Set(excluded.value)
    const include = !pageAllIncluded.value
    for (const item of itemsPage.value?.items ?? []) {
      if (include) next.delete(itemKey(item))
      else next.add(itemKey(item))
    }
    excluded.value = next
  }

  // ── Načtení a polling ────────────────────────────────────────────────────
  async function load() {
    loading.value = true
    error.value = ''
    try {
      state.value = await api.status()
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

  function doneMessage(j: J): string {
    const key = j.kind === 'dry_run' ? 'done_dry' : j.kind === 'rollback' ? 'done_rollback' : 'done_apply'
    return t(`${i18nPrefix}.${key}`, { n: j.changed_count })
  }

  function poll(jobId: number) {
    // Úloha běží dál i bez nás, takže výpadek jednoho dotazu není důvod přestat se ptát.
    let failures = 0
    const tick = async () => {
      try {
        const j = await api.job(jobId)
        failures = 0
        running.value = j
        if (j.status === 'queued' || j.status === 'running') {
          timer = setTimeout(tick, 1500)
          return
        }
        timer = null
        cancelling.value = false
        if (j.status === 'failed') {
          toast.error(j.last_error || t(`${i18nPrefix}.failed`))
        } else if (j.status === 'cancelled') {
          toast.warning(t(`${i18nPrefix}.cancelled`))
        } else {
          toast.success(doneMessage(j))
        }
        state.value = await api.status()
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

  async function cancel() {
    const id = running.value?.id
    if (!id || cancelling.value) return
    cancelling.value = true
    try {
      await api.cancel(id)
    } catch (e) {
      cancelling.value = false
      toast.error(apiErrorMessage(e))
    }
  }

  return {
    state, running, loading, starting, cancelling, error, isRunning, percent,
    load, poll, startJob, cancel, stopPolling,
    selected, activeTab, page, itemsPage, itemsLoading, itemsError, counts, excluded,
    tabs, selectedFinished, canExclude, wouldChangeCount, applyCount, canApplySelected,
    selectJob, loadItems, setTab, setPage, isIncluded, toggleItem, pageAllIncluded, togglePage,
  }
}

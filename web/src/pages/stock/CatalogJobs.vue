<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const jobs = ref<CatalogJob[]>([])
const loading = ref(false)
const actingId = ref<number | null>(null)
const hasMore = ref(false)
const loadingMore = ref(false)
let timer: ReturnType<typeof setInterval> | undefined
const active = computed(() => jobs.value.some(j => j.status === 'queued' || j.status === 'running'))
const STATUS: Record<string, string> = { queued: 'bg-neutral-100 text-neutral-600', running: 'bg-primary-50 text-primary-700', completed: 'bg-success-50 text-success-700', failed: 'bg-danger-50 text-danger-700', cancelled: 'bg-warning-50 text-warning-700' }
function isActive(job: CatalogJob) { return job.status === 'queued' || job.status === 'running' }
function canManage(job: CatalogJob) { return job.kind === 'stock_valuation' ? auth.canWrite('stock') : auth.canWrite('eshop.write') }
function failedItems(job: CatalogJob): Array<{ item_id: number; error_code: string; currency_code?: string }> {
  return Array.isArray(job.report?.failed_items) ? job.report.failed_items : []
}
function replacementIds(job: CatalogJob): number[] {
  const ids = Array.isArray(job.report?.replacement_job_ids) ? job.report.replacement_job_ids : []
  return job.report?.replacement_job_id ? [...ids, Number(job.report.replacement_job_id)] : ids
}
async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const rows = await catalogJobsApi.list()
    const existing = new Map(jobs.value.map(job => [job.id, job]))
    for (const row of rows) existing.set(row.id, row)
    jobs.value = [...existing.values()].sort((a, b) => b.id - a.id)
    if (!silent) hasMore.value = rows.length === 50
    if (silent) {
      const olderActive = jobs.value.filter(job => !rows.some(row => row.id === job.id) && ['queued', 'running'].includes(job.status))
      for (const job of olderActive) Object.assign(job, await catalogJobsApi.get(job.id))
    }
  } catch (e: any) { if (!silent) toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { loading.value = false }
}
async function loadMore() {
  if (loadingMore.value || !jobs.value.length) return
  loadingMore.value = true
  try {
    const rows = await catalogJobsApi.list({ before_id: jobs.value[jobs.value.length - 1]!.id })
    jobs.value.push(...rows.filter(row => !jobs.value.some(job => job.id === row.id)))
    hasMore.value = rows.length === 50
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { loadingMore.value = false }
}
async function retry(id: number) { actingId.value = id; try { await catalogJobsApi.retry(id); await load(true); toast.success(t('eshop.jobs.retry_done')) } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) } finally { actingId.value = null } }
async function cancel(id: number) { actingId.value = id; try { await catalogJobsApi.cancel(id); await load(true) } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) } finally { actingId.value = null } }
async function recompute() { actingId.value = -1; try { await catalogJobsApi.recomputePrices(); await load(true); toast.success(t('eshop.jobs.queued')) } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) } finally { actingId.value = null } }
onMounted(async () => { await load(); timer = setInterval(() => { if (active.value) load(true) }, 3000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4"><div><h1 class="text-2xl font-semibold">{{ t('eshop.jobs.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.jobs.subtitle') }}</p></div><button v-if="auth.canWrite('eshop.write')" type="button" :disabled="actingId !== null" :class="btnFilled('primary')" @click="recompute"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.93 4.93A10 10 0 1 1 2 12h2a8 8 0 1 0 2.34-5.66L9 9H2V2l2.93 2.93z" /></svg>{{ t('eshop.jobs.recompute_prices') }}</button></div>
    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="jobs.length === 0" boxed accent="neutral" icon="chart" :title="t('eshop.jobs.empty_title')" :message="t('eshop.jobs.empty_hint')" />
    <div v-else class="space-y-2">
      <article v-for="job in jobs" :key="job.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="font-medium">{{ t(`eshop.jobs.kind.${job.kind}`, job.kind) }}</span><span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS[job.status]">{{ t(`eshop.jobs.status.${job.status}`) }}</span></div><p class="text-xs text-neutral-500 mt-1">#{{ job.id }} · {{ formatDateTime(job.created_at) }}<span v-if="job.error_code"> · {{ job.error_code }}</span></p></div><div class="flex flex-wrap gap-2"><RouterLink v-if="job.stock_take_id" :to="`/stock/takes/${job.stock_take_id}`" :class="btnOutline('primary')"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>{{ t('eshop.jobs.open_stock_take') }}</RouterLink><RouterLink v-if="job.kind === 'stock_valuation' && !job.stock_take_id" :to="`/stock/reports?valuation_job=${job.id}`" :class="btnOutline('neutral')"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5h10v14H5V9m4 6L19 5" /></svg>{{ t('eshop.jobs.open_valuation') }}</RouterLink><template v-if="!job.stock_take_id && canManage(job)"><button v-if="job.status === 'failed' || job.status === 'cancelled'" type="button" :disabled="actingId === job.id" :class="btnOutline('primary')" @click="retry(job.id)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 0 0 4.582 9M4.582 9H9m11 11v-5h-.581A8 8 0 0 1 4.58 13H15" /></svg>{{ t('common.retry') }}</button></template></div></div>
        <CatalogJobProgress v-if="isActive(job)" class="mt-3" :job="job" :cancelling="actingId === job.id" :can-cancel="!job.stock_take_id && canManage(job)" @cancel="cancel(job.id)" />
        <details class="mt-3 text-sm">
          <summary class="cursor-pointer text-primary-700">{{ t('eshop.jobs.run_detail') }}</summary>
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2 text-neutral-600">
            <div><dt>{{ t('eshop.jobs.attempts') }}</dt><dd>{{ job.attempts }}</dd></div>
            <div><dt>{{ t('eshop.jobs.last_update') }}</dt><dd>{{ formatDateTime(job.updated_at) }}</dd></div>
            <div v-if="job.finished_at"><dt>{{ t('eshop.jobs.finished_at') }}</dt><dd>{{ formatDateTime(job.finished_at) }}</dd></div>
            <div v-if="job.report?.processed !== undefined"><dt>{{ t('eshop.jobs.processed') }}</dt><dd>{{ job.report.processed }}</dd></div>
          </dl>
          <p v-if="replacementIds(job).length" class="mt-2 text-sm text-warning-700">{{ t('eshop.jobs.replacement_runs') }} {{ replacementIds(job).map(id => `#${id}`).join(', ') }}</p>
          <ul v-if="failedItems(job).length" class="mt-2 space-y-1 text-danger-700">
            <li v-for="failure in failedItems(job).slice(0, 20)" :key="failure.item_id">#{{ failure.item_id }}: {{ t(`eshop.jobs.errors.${failure.error_code}`, t('common.error')) }} {{ failure.currency_code ?? '' }}</li>
          </ul>
          <p v-if="failedItems(job).length > 20" class="mt-1 text-xs text-neutral-500">{{ t('eshop.jobs.first_errors') }}</p>
        </details>
        <p v-if="failedItems(job).length" class="mt-2 rounded border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('eshop.jobs.completed_errors', { count: failedItems(job).length }) }}</p>
      </article>
      <button v-if="hasMore" type="button" :disabled="loadingMore" :class="btnOutline('neutral')" @click="loadMore"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" /></svg>{{ t('eshop.jobs.load_older') }}</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import {
  isBatchUploadReady, moneyS3BatchApi,
  type BatchFiling, type BatchItem, type BatchJob, type BatchStartParams, type BatchUpload, type BatchUploadReady, type Criteria,
} from '@/api/moneyS3Batch'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'

/**
 * Průvodce „Přechod z Money S3", režim Dávka: účetní kancelář převede víc firem
 * najednou. Zálohy se nahrávají po jedné (server každou po nahrání přečte), firmy se
 * poznají podle IČO v záloze a chybějící se založí. Dávka běží jedním jobem na pozadí,
 * pád jedné firmy nezastaví ostatní a souhrnný protokol ukazuje K1–K4 po firmách.
 */

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const uploads = ref<BatchUpload[]>([])
const filings = ref<BatchFiling[]>([])
const canCreateCompanies = ref(false)
const currentGroupId = ref<number | null>(null)
const selected = ref<Set<string>>(new Set())
const loading = ref(false)

// Fronta souborů k nahrání: server zpracovává jednu zálohu firmy najednou.
interface QueueEntry { file: File; state: 'waiting' | 'uploading' | 'processing' | 'done' | 'failed'; percent: number; error: string | null }
const queue = ref<QueueEntry[]>([])
const uploading = ref(false)
const filingBusy = ref(false)

const fromYear = ref<'auto' | 'all'>('auto')
const toYear = ref<'auto' | number>('auto')
const openingOnlyNext = ref(false)
const existing = ref<'skip' | 'update'>('skip')
const closeHistory = ref(true)
const disposalYearTax = ref<'half' | 'none'>('half')
const groupMode = ref<'none' | 'current' | 'new'>('none')
const groupName = ref('')
const relatedParties = ref(false)
const useRegistry = ref(true)
const takeOverFilings = ref(true)
const confirmed = ref(false)

const job = ref<FileImportJob | null>(null)
const jobMode = ref<'dry_run' | 'import' | null>(null)
const batch = ref<BatchJob | null>(null)
const history = ref<BatchJob[]>([])
const starting = ref(false)
const cancelling = ref(false)
const openProtocol = ref<number | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false

const readyUploads = computed(() => uploads.value.filter(isBatchUploadReady))
const pendingUploads = computed(() => uploads.value.filter(u => !isBatchUploadReady(u)))
const selectedTokens = computed(() => readyUploads.value.filter(u => selected.value.has(u.token)).map(u => u.token))
/** Účetní roky vybraných záloh (volba „Převést do roku"). */
const selectedYears = computed(() => {
  const years = new Set<number>()
  for (const u of readyUploads.value) {
    if (!selected.value.has(u.token)) continue
    for (const y of u.agenda.years ?? []) if (typeof y.fiscal_year === 'number') years.add(y.fiscal_year)
  }
  return [...years].sort((a, b) => a - b)
})
const selectedNew = computed(() => readyUploads.value.filter(u => selected.value.has(u.token) && u.company.access === 'none').length)
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
const percent = computed(() => job.value?.total_items ? Math.min(100, Math.round((job.value.processed / job.value.total_items) * 100)) : null)
const missingRights = computed(() => {
  const required: PermissionKey[] = ['accounting.journal.write', 'settings.company.write']
  if (closeHistory.value) required.push('accounting.periods.close')
  return required.filter(key => !auth.canWrite(key))
})

function errorMessage(error: any, fallback: string): string {
  return String(error?.response?.data?.error?.message ?? '').trim() || fallback
}

async function refresh(): Promise<void> {
  loading.value = true
  try {
    const data = await moneyS3BatchApi.list()
    const known = new Set(uploads.value.map(u => u.token))
    uploads.value = data.items
    filings.value = data.filings
    canCreateCompanies.value = data.can_create_companies
    currentGroupId.value = data.current_group_id
    // Nově připravené zálohy se do dávky rovnou vyberou; zálohy bez přístupu ne.
    for (const u of data.items) {
      if (isBatchUploadReady(u) && !known.has(u.token) && u.company.access !== 'forbidden' && u.company.access !== 'ambiguous') selected.value.add(u.token)
    }
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.load_failed')))
  } finally {
    loading.value = false
  }
}

async function loadHistory(): Promise<void> {
  try {
    history.value = (await moneyS3BatchApi.jobs()).items
  } catch {
    history.value = []
  }
}

function onFiles(event: Event): void {
  const input = event.target as HTMLInputElement
  for (const file of Array.from(input.files ?? [])) {
    queue.value.push({ file, state: 'waiting', percent: 0, error: null })
  }
  input.value = ''
}

function wait(ms: number): Promise<void> {
  return new Promise(resolve => { pollTimer = setTimeout(resolve, ms) })
}

async function waitProcessed(token: string): Promise<BatchUpload | null> {
  for (let i = 0; i < 1800 && !disposed; i++) {
    await refresh()
    const found = uploads.value.find(u => u.token === token) ?? null
    if (found === null || found.status === 'ready' || found.status === 'failed') return found
    await wait(2000)
  }
  return null
}

async function uploadQueue(): Promise<void> {
  if (uploading.value) return
  uploading.value = true
  try {
    for (const entry of queue.value) {
      if (entry.state !== 'waiting' || disposed) continue
      entry.state = 'uploading'
      try {
        const done = await moneyS3BatchApi.uploadChunked(entry.file, (sent, total) => { entry.percent = total ? Math.round((sent / total) * 100) : 0 })
        entry.state = 'processing'
        const result = await waitProcessed(done.token)
        if (result !== null && result.status === 'failed') {
          entry.state = 'failed'
          entry.error = (result as { error?: string | null }).error ?? t('money_s3.batch.upload_failed')
        } else {
          entry.state = 'done'
        }
      } catch (error: any) {
        entry.state = 'failed'
        entry.error = errorMessage(error, t('money_s3.batch.upload_failed'))
      }
    }
  } finally {
    uploading.value = false
    queue.value = queue.value.filter(e => e.state !== 'done')
  }
}

async function removeUpload(token: string): Promise<void> {
  try {
    await moneyS3BatchApi.deleteUpload(token)
    selected.value.delete(token)
    await refresh()
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.delete_failed')))
  }
}

async function onFilings(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  filingBusy.value = true
  let ok = 0
  for (const file of files) {
    try {
      await moneyS3BatchApi.uploadFiling(file)
      ok++
    } catch (error: any) {
      toast.error(`${file.name}: ${errorMessage(error, t('money_s3.batch.filing_failed'))}`)
    }
  }
  filingBusy.value = false
  if (ok > 0) toast.success(t('money_s3.batch.filings_added', { n: ok }))
  await refresh()
}

async function removeFiling(id: string): Promise<void> {
  try {
    await moneyS3BatchApi.deleteFiling(id)
    await refresh()
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.delete_failed')))
  }
}

function toggle(token: string): void {
  if (selected.value.has(token)) selected.value.delete(token)
  else selected.value.add(token)
  selected.value = new Set(selected.value)
}

async function start(mode: 'dry_run' | 'import'): Promise<void> {
  const params: BatchStartParams = {
    tokens: selectedTokens.value,
    filing_ids: filings.value.map(f => f.id),
    mode,
    from_year: fromYear.value,
    to_year: toYear.value,
    opening_only_next: openingOnlyNext.value,
    existing: existing.value,
    close_history: closeHistory.value,
    disposal_year_tax: disposalYearTax.value,
    group_mode: groupMode.value,
    group_name: groupName.value,
    related_parties: relatedParties.value,
    use_registry: useRegistry.value,
    take_over_filings: takeOverFilings.value,
  }
  starting.value = true
  try {
    const started = await moneyS3BatchApi.start(params)
    jobMode.value = mode
    confirmed.value = false
    if (started.superseded.length) toast.info(t('money_s3.batch.superseded', { n: started.superseded.length }))
    await pollJob(started.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.start_failed')))
  } finally {
    starting.value = false
  }
}

async function pollJob(id: number): Promise<void> {
  try {
    job.value = await fetchImportJob(id)
    batch.value = await moneyS3BatchApi.job(id)
  } catch {
    return
  }
  if (jobRunning.value && !disposed) {
    pollTimer = setTimeout(() => { void pollJob(id) }, 2000)
  } else {
    await Promise.all([refresh(), loadHistory()])
  }
}

async function cancel(): Promise<void> {
  if (!job.value) return
  cancelling.value = true
  try {
    await cancelImportJob(job.value.id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.cancel_failed')))
  } finally {
    cancelling.value = false
  }
}

async function showBatch(item: BatchJob): Promise<void> {
  try {
    batch.value = await moneyS3BatchApi.job(item.id)
    jobMode.value = (item.options.mode as 'dry_run' | 'import' | undefined) ?? null
    job.value = null
    openProtocol.value = null
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.batch.load_failed')))
  }
}

function companyLabel(u: BatchUploadReady): string {
  if (u.company.access === 'ok') return t('money_s3.batch.company_existing', { name: u.company.name ?? '' })
  if (u.company.access === 'forbidden') return t('money_s3.batch.company_forbidden')
  if (u.company.access === 'ambiguous') return t('money_s3.batch.company_ambiguous')
  return canCreateCompanies.value ? t('money_s3.batch.company_new') : t('money_s3.batch.company_new_forbidden')
}

function criteriaClass(ok: boolean | null | undefined): string {
  if (ok === null || ok === undefined) return 'bg-neutral-100 text-neutral-500'
  return ok ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600'
}

function statusClass(status: string): string {
  if (status === 'completed') return 'bg-success-50 text-success-600'
  if (status === 'completed_with_warnings') return 'bg-warning-50 text-warning-700'
  if (status === 'failed') return 'bg-danger-50 text-danger-600'
  return 'bg-neutral-100 text-neutral-600'
}

function itemName(item: BatchItem): string {
  return item.target_name ?? item.summary?.identity?.name ?? item.agenda_name ?? item.file_name ?? '—'
}

function criteriaOf(item: BatchItem): Criteria | null {
  return item.summary?.criteria?.total ?? null
}

const actions = computed<ActionItem[]>(() => {
  const nothing = selectedTokens.value.length === 0
  const blockedReason = nothing ? t('money_s3.batch.select_first') : ''
  const createBlocked = selectedNew.value > 0 && !canCreateCompanies.value
  const importReason = missingRights.value.length
    ? t('money_s3.rights_missing', { rights: missingRights.value.join(', ') })
    : createBlocked ? t('money_s3.batch.create_forbidden') : !confirmed.value ? t('money_s3.batch.confirm_first') : blockedReason
  return [
    { key: 'upload', label: uploading.value ? t('money_s3.batch.uploading') : t('money_s3.batch.upload'), icon: 'upload', tier: 'secondary', variant: 'neutral',
      disabled: uploading.value || !queue.value.some(e => e.state === 'waiting'), disabledReason: t('money_s3.batch.choose_files_first'), loading: uploading.value, run: () => { void uploadQueue() } },
    { key: 'dry', label: t('money_s3.batch.dry_run_start'), icon: 'play', tier: jobMode.value === 'dry_run' && !jobRunning.value ? 'secondary' : 'primary', variant: jobMode.value === 'dry_run' && !jobRunning.value ? 'neutral' : 'primary',
      disabled: nothing || jobRunning.value, disabledReason: blockedReason, loading: starting.value, run: () => { void start('dry_run') } },
    { key: 'import', label: t('money_s3.batch.import_start'), icon: 'play', tier: jobMode.value === 'dry_run' && !jobRunning.value ? 'primary' : 'secondary', variant: 'warning',
      disabled: nothing || jobRunning.value || !confirmed.value || createBlocked || missingRights.value.length > 0, disabledReason: importReason, loading: starting.value, run: () => { void start('import') } },
    { key: 'refresh', label: t('money_s3.batch.refresh'), icon: 'cycle', tier: 'overflow', variant: 'neutral', run: () => { void refresh() } },
  ]
})

onMounted(() => { void refresh(); void loadHistory() })
onBeforeUnmount(() => {
  disposed = true
  if (pollTimer) clearTimeout(pollTimer)
})
</script>

<template>
  <div class="space-y-5">
    <div class="rounded-lg border border-primary-200 bg-primary-50/50 px-4 py-3 text-sm text-primary-700" data-testid="money-s3-batch-intro">
      {{ t('money_s3.batch.intro') }}
    </div>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.batch.backups_title') }}</h2>
      <p class="mb-4 text-sm text-neutral-500">{{ t('money_s3.batch.backups_hint') }}</p>
      <label class="block max-w-xl text-sm font-medium">
        {{ t('money_s3.batch.choose_files') }}
        <input type="file" multiple accept=".lz,.zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="batch-backup-input" :disabled="uploading" @change="onFiles" />
      </label>
      <ul v-if="queue.length" class="mt-3 space-y-1 text-sm" data-testid="batch-queue">
        <li v-for="(e, i) in queue" :key="i" class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ e.file.name }}</span>
          <span class="rounded-full px-2 py-0.5 text-xs" :class="e.state === 'failed' ? 'bg-danger-50 text-danger-600' : 'bg-neutral-100 text-neutral-600'">
            {{ e.state === 'uploading' ? t('money_s3.uploading_percent', { percent: e.percent }) : t(`money_s3.batch.queue_state.${e.state}`) }}
          </span>
          <span v-if="e.error" class="text-xs text-danger-600">{{ e.error }}</span>
        </li>
      </ul>

      <div class="mt-5 overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
            <tr>
              <th class="px-3 py-2"></th>
              <th class="px-3 py-2">{{ t('money_s3.ico') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.agenda') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_years') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_suggested') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_company') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-if="!uploads.length"><td colspan="7" class="px-3 py-3 text-neutral-500">{{ loading ? t('common.loading') : t('money_s3.batch.no_backups') }}</td></tr>
            <tr v-for="u in readyUploads" :key="u.token">
              <td class="px-3 py-2"><input type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="selected.has(u.token)"
                :disabled="u.company.access === 'forbidden' || u.company.access === 'ambiguous'" :data-testid="`batch-select-${u.token}`" @change="toggle(u.token)" /></td>
              <td class="px-3 py-2 whitespace-nowrap">{{ u.agenda.ico || '—' }}</td>
              <td class="px-3 py-2"><span class="font-medium">{{ u.agenda.name || u.file_name }}</span><br /><span class="text-xs text-neutral-500">{{ u.file_name }} · {{ u.agenda.backup_at }}</span></td>
              <td class="px-3 py-2 whitespace-nowrap">{{ u.agenda.years.map(y => y.fiscal_year).filter(Boolean).join(', ') }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ u.suggested_from_year ?? t('money_s3.batch.all_years') }}</td>
              <td class="px-3 py-2">
                <span class="rounded-full px-2 py-0.5 text-xs" :class="u.company.access === 'ok' ? 'bg-primary-50 text-primary-700' : u.company.access === 'none' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600'">{{ companyLabel(u) }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600" :title="t('money_s3.batch.remove')" :aria-label="t('money_s3.batch.remove')" @click="removeUpload(u.token)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
            </tr>
            <tr v-for="u in pendingUploads" :key="u.token" class="text-neutral-500">
              <td class="px-3 py-2"></td>
              <td class="px-3 py-2" colspan="4">{{ u.file_name }}</td>
              <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="u.status === 'failed' ? 'bg-danger-50 text-danger-600' : 'bg-neutral-100 text-neutral-600'">{{ u.status === 'failed' ? ((u as { error?: string | null }).error || t('money_s3.batch.upload_failed')) : t(`money_s3.batch.queue_state.${u.status === 'uploading' ? 'uploading_idle' : 'processing'}`) }}</span></td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600" :title="t('money_s3.batch.remove')" :aria-label="t('money_s3.batch.remove')" @click="removeUpload(u.token)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="selectedNew > 0" class="mt-2 text-sm" :class="canCreateCompanies ? 'text-neutral-500' : 'text-danger-600'">
        {{ canCreateCompanies ? t('money_s3.batch.will_create', { n: selectedNew }) : t('money_s3.batch.create_forbidden') }}
      </p>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.batch.filings_title') }}</h2>
      <p class="mb-4 text-sm text-neutral-500">{{ t('money_s3.batch.filings_hint') }}</p>
      <label class="block max-w-xl text-sm font-medium">
        {{ t('money_s3.batch.choose_filings') }}
        <input type="file" multiple accept=".xml" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="batch-filing-input" :disabled="filingBusy" @change="onFilings" />
      </label>
      <ul v-if="filings.length" class="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm">
        <li v-for="f in filings" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
          <span><strong>{{ f.ic }}</strong> · {{ f.year }} · {{ t(`money_s3.batch.forma.${f.forma}`) }} <span class="text-xs text-neutral-500">{{ f.name }} · {{ f.file_name }}</span></span>
          <button type="button" class="rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600" :title="t('money_s3.batch.remove')" :aria-label="t('money_s3.batch.remove')" @click="removeFiling(f.id)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </li>
      </ul>
      <label v-if="filings.length" class="mt-3 flex cursor-pointer items-start gap-3">
        <input v-model="takeOverFilings" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
        <span class="text-sm"><span class="font-medium">{{ t('money_s3.batch.take_over_filings') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.batch.take_over_filings_hint') }}</span></span>
      </label>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-4 text-lg font-semibold">{{ t('money_s3.options_title') }}</h2>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <label class="block text-sm font-medium">{{ t('money_s3.from_year') }}
          <select v-model="fromYear" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="batch-from-year">
            <option value="auto">{{ t('money_s3.batch.from_year_auto') }}</option>
            <option value="all">{{ t('money_s3.from_year_all') }}</option>
          </select>
          <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('money_s3.batch.from_year_hint') }}</span>
        </label>
        <label class="block text-sm font-medium">{{ t('money_s3.to_year') }}
          <select v-model="toYear" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="batch-to-year">
            <option value="auto">{{ t('money_s3.batch.to_year_auto') }}</option>
            <option v-for="y in selectedYears" :key="y" :value="y">{{ y }}</option>
          </select>
          <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('money_s3.batch.to_year_hint') }}</span>
        </label>
        <label class="block text-sm font-medium">{{ t('money_s3.batch.existing') }}
          <select v-model="existing" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="batch-existing">
            <option value="skip">{{ t('money_s3.batch.existing_skip') }}</option>
            <option value="update">{{ t('money_s3.batch.existing_update') }}</option>
          </select>
          <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('money_s3.batch.existing_hint') }}</span>
        </label>
        <label class="block text-sm font-medium">{{ t('money_s3.batch.group') }}
          <select v-model="groupMode" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm">
            <option value="none">{{ t('money_s3.batch.group_none') }}</option>
            <option v-if="currentGroupId !== null" value="current">{{ t('money_s3.batch.group_current') }}</option>
            <option value="new">{{ t('money_s3.batch.group_new') }}</option>
          </select>
          <input v-if="groupMode === 'new'" v-model="groupName" type="text" maxlength="190" :placeholder="t('money_s3.batch.group_name')" class="mt-2 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" />
          <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('money_s3.batch.group_hint') }}</span>
        </label>
        <label class="block text-sm font-medium">{{ t('money_s3.batch.disposal_year_tax') }}
          <select v-model="disposalYearTax" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm">
            <option value="half">{{ t('money_s3.batch.disposal_half') }}</option>
            <option value="none">{{ t('money_s3.batch.disposal_none') }}</option>
          </select>
        </label>
      </div>
      <div class="mt-4 space-y-2">
        <label class="flex cursor-pointer items-start gap-3">
          <input v-model="closeHistory" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm"><span class="font-medium">{{ t('money_s3.close_history') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.close_history_hint') }}</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3">
          <input v-model="openingOnlyNext" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="batch-opening-only-next" />
          <span class="text-sm"><span class="font-medium">{{ t('money_s3.opening_only_next') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.opening_only_next_hint') }}</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3">
          <input v-model="relatedParties" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm"><span class="font-medium">{{ t('money_s3.batch.related_parties') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.batch.related_parties_hint') }}</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3">
          <input v-model="useRegistry" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm"><span class="font-medium">{{ t('money_s3.batch.use_registry') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.batch.use_registry_hint') }}</span></span>
        </label>
      </div>
      <label v-if="!jobRunning" class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
        <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="batch-confirm" />
        <span class="text-sm text-warning-700">{{ t('money_s3.batch.import_confirm', { n: selectedTokens.length }) }}</span>
      </label>
      <p v-if="missingRights.length" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600">
        {{ t('money_s3.rights_missing', { rights: missingRights.join(', ') }) }}
      </p>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
      counts-key="money_s3.batch.job_counts" background-hint-key="money_s3.background_hint" running-key="money_s3.batch.running"
      cancel-key="money_s3.cancel" cancelling-key="money_s3.cancelling" @cancel="cancel" />

    <section v-if="batch" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-testid="batch-protocol">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">{{ t('money_s3.batch.protocol_title', { id: batch.id }) }} · {{ t(`money_s3.mode.${(batch.options.mode ?? 'dry_run')}`) }}</h2>
        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(batch.status)">{{ t(`money_s3.status.${batch.status === 'queued' ? 'running' : batch.status}`) }}</span>
      </div>
      <p class="mb-3 text-sm text-neutral-500">{{ t('money_s3.batch.criteria_legend') }}</p>
      <div class="overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
            <tr>
              <th class="px-3 py-2">#</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_company') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_action') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.from_year') }}</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_status') }}</th>
              <th class="px-3 py-2">K1–K4</th>
              <th class="px-3 py-2">{{ t('money_s3.batch.col_filings') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="item in batch.items" :key="item.id">
              <tr>
                <td class="px-3 py-2">{{ item.position }}</td>
                <td class="px-3 py-2"><span class="font-medium">{{ itemName(item) }}</span><br /><span class="text-xs text-neutral-500">{{ item.agenda_ico }}<template v-if="item.target_supplier_id"> · #{{ item.target_supplier_id }}</template></span></td>
                <td class="px-3 py-2 whitespace-nowrap">{{ item.company_action ? t(`money_s3.batch.action.${item.company_action}`) : '—' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ item.company_action === 'skipped' || item.status === 'queued' ? '—' : (item.from_year ?? t('money_s3.batch.all_years')) }}</td>
                <td class="px-3 py-2"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="statusClass(item.status)">{{ t(`money_s3.batch.item_status.${item.status}`) }}</span></td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <template v-if="criteriaOf(item)">
                    <span v-for="k in (['K1', 'K2', 'K3', 'K4'] as const)" :key="k" class="mr-1 inline-block rounded px-1.5 py-0.5 text-xs" :class="criteriaClass(criteriaOf(item)?.[k])">{{ k }}</span>
                  </template>
                  <span v-else>—</span>
                </td>
                <td class="px-3 py-2 text-xs">
                  <template v-if="item.summary?.filings?.length">{{ item.summary.filings.map(f => `${f.year}: ${t(`money_s3.batch.filing_status.${f.status}`)}`).join(', ') }}</template>
                  <template v-else-if="item.summary?.filings_available?.length">{{ t('money_s3.batch.filings_pending', { years: item.summary.filings_available.join(', ') }) }}</template>
                  <template v-else>—</template>
                </td>
                <td class="px-3 py-2 text-right">
                  <button v-if="item.summary?.protocol" type="button" class="whitespace-nowrap text-xs font-medium text-primary-600 hover:underline" @click="openProtocol = openProtocol === item.id ? null : item.id">
                    {{ openProtocol === item.id ? t('money_s3.batch.hide_protocol') : t('money_s3.batch.show_protocol') }}
                  </button>
                  <span v-else-if="item.run_id" class="text-xs text-neutral-500">{{ t('money_s3.batch.run_ref', { id: item.run_id }) }}</span>
                </td>
              </tr>
              <tr v-if="item.error || item.summary?.notices?.length || item.summary?.note">
                <td></td>
                <td colspan="7" class="px-3 pb-3 text-xs">
                  <p v-if="item.error" class="text-danger-600">{{ item.error }}</p>
                  <p v-if="item.summary?.note" class="text-neutral-500">{{ item.summary.note }}</p>
                  <ul v-if="item.summary?.notices?.length" class="list-disc pl-4 text-warning-700">
                    <li v-for="(n, i) in item.summary.notices" :key="i">{{ n }}</li>
                  </ul>
                </td>
              </tr>
              <tr v-if="openProtocol === item.id && item.summary?.protocol">
                <td colspan="8" class="bg-neutral-50 p-3">
                  <MoneyS3Protocol :run="{ id: item.id, job_id: batch.id, mode: 'dry_run', status: item.summary.protocol.status, agenda_ico: item.agenda_ico, agenda_name: item.agenda_name, money_version: null, created_at: batch.created_at ?? '', finished_at: batch.finished_at, protocol: item.summary.protocol }" />
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ t('money_s3.batch.history_title') }}</h2>
      <p v-if="!history.length" class="px-4 py-3 text-sm text-neutral-500">{{ t('money_s3.batch.history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <button v-for="h in history" :key="h.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showBatch(h)">
          <span><strong>#{{ h.id }} · {{ t(`money_s3.mode.${h.options.mode ?? 'dry_run'}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ t('money_s3.batch.history_companies', { n: h.items.length }) }} · {{ h.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(h.status)">{{ t(`money_s3.status.${h.status === 'queued' ? 'running' : h.status}`) }}</span>
        </button>
      </div>
    </section>
  </div>
</template>

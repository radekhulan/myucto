<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  instanceExportApi,
  type InstanceExportJob,
  type InstanceExportOverview,
  type InstanceExportPart,
} from '@/api/instanceExport'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

/**
 * Balíček firmy složený z obnovitelného archivu a volitelných účetních podkladů.
 * Běží na pozadí, protože jednotlivé části mohou obsahovat celé roky dokladů.
 */

const { t } = useI18n()
const toast = useToast()

const ALL_PARTS: InstanceExportPart[] = ['restore', 'data', 'documents', 'files', 'vat_exports', 'closing_packages']

const overview = ref<InstanceExportOverview | null>(null)
const loading = ref(false)
const starting = ref(false)
const downloadingId = ref<number | null>(null)
const selectedParts = ref<InstanceExportPart[]>([...ALL_PARTS])
const dateFrom = ref('')
const dateTo = ref('')
const detail = ref<InstanceExportJob | null>(null)
const partsInitialized = ref(false)

let pollTimer: ReturnType<typeof setInterval> | null = null

const items = computed(() => overview.value?.items ?? [])
const active = computed(() => overview.value?.active ?? null)
const isBusy = computed(() => {
  const s = active.value?.status
  return s === 'queued' || s === 'running'
})
const progressPercent = computed(() => {
  const job = active.value
  if (!job?.total_steps) return null
  return Math.min(100, Math.round((job.processed_steps / job.total_steps) * 100))
})
const visibleParts = computed(() => ALL_PARTS.filter(part => {
  if (part === 'vat_exports') return overview.value?.profile?.is_vat_payer ?? true
  if (part === 'closing_packages') return overview.value?.profile?.accounting_mode === 'double_entry'
  return true
}))
const hasAccountingRetention = computed(() => overview.value?.profile?.accounting_mode === 'double_entry')

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

async function load() {
  loading.value = true
  try {
    overview.value = await instanceExportApi.overview()
    if (!partsInitialized.value) {
      selectedParts.value = [...visibleParts.value]
      partsInitialized.value = true
    }
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

// Polling jen dokud něco běží — hotový export se nedotazuje donekonečna.
function syncPolling() {
  if (isBusy.value && pollTimer === null) {
    pollTimer = setInterval(() => { void load() }, 4000)
  } else if (!isBusy.value && pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(async () => {
  await load()
  syncPolling()
})
onBeforeUnmount(() => {
  if (pollTimer !== null) clearInterval(pollTimer)
})

function togglePart(part: InstanceExportPart) {
  const idx = selectedParts.value.indexOf(part)
  if (idx >= 0) selectedParts.value.splice(idx, 1)
  else selectedParts.value.push(part)
}

async function start() {
  if (selectedParts.value.length === 0) {
    toast.error(t('instance_export.pick_part'))
    return
  }
  starting.value = true
  try {
    await instanceExportApi.start({
      parts: selectedParts.value,
      date_from: dateFrom.value || null,
      date_to: dateTo.value || null,
    })
    toast.success(t('instance_export.started'))
    await load()
    syncPolling()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    starting.value = false
  }
}

async function download(job: InstanceExportJob) {
  downloadingId.value = job.id
  try {
    const r = await instanceExportApi.download(job.id)
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = job.result_name || `myucto-export-${job.id}.zip`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    downloadingId.value = null
  }
}

async function cancel(job: InstanceExportJob) {
  try {
    await instanceExportApi.cancel(job.id)
    toast.success(t('instance_export.cancel_requested'))
    await load()
  } catch (e: any) {
    toast.error(errorMessage(e))
  }
}

async function remove(job: InstanceExportJob) {
  if (!confirm(t('instance_export.delete_confirm', { file: job.result_name || `#${job.id}` }))) return
  try {
    await instanceExportApi.remove(job.id)
    toast.success(t('common.deleted'))
    if (detail.value?.id === job.id) detail.value = null
    await load()
  } catch (e: any) {
    toast.error(errorMessage(e))
  }
}

async function openDetail(job: InstanceExportJob) {
  try {
    detail.value = await instanceExportApi.status(job.id)
  } catch (e: any) {
    toast.error(errorMessage(e))
  }
}

async function copyChecksum(job: InstanceExportJob) {
  if (!job.sha256) return
  try {
    await navigator.clipboard.writeText(job.sha256)
    toast.success(t('instance_export.checksum_copied'))
  } catch {
    toast.error(t('common.error'))
  }
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return '—'
  const units = ['B', 'kB', 'MB', 'GB', 'TB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`
}

function statusClass(status: string): string {
  return status === 'completed' ? 'bg-success-50 text-success-700 border-success-200'
    : status === 'failed' ? 'bg-danger-50 text-danger-700 border-danger-200'
    : status === 'cancelled' ? 'bg-neutral-100 text-neutral-600 border-neutral-200'
    : 'bg-primary-50 text-primary-700 border-primary-200'
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('instance_export.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('instance_export.subtitle') }}</p>
    </div>

    <!-- Kontrakt balíčku: přenositelná data i bezpečně obnovitelný archiv. -->
    <div class="bg-primary-50/50 border border-primary-100 rounded-lg p-3 mb-4 text-sm text-neutral-700">
      {{ t('instance_export.info') }}
      <span class="block text-xs text-neutral-500 mt-1">{{ t('instance_export.no_restore_note') }}</span>
    </div>

    <div v-if="overview && !overview.encrypted"
      class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm text-warning-800">
      {{ t('instance_export.not_encrypted') }}
    </div>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 text-sm text-neutral-700">
      <h2 class="font-medium text-neutral-800 mb-2">{{ t('instance_export.restore.title') }}</h2>
      <ol class="list-decimal list-inside space-y-1 text-sm text-neutral-600">
        <li>{{ t('instance_export.restore.step_extract') }}</li>
        <li>{{ t('instance_export.restore.step_dry_run') }}</li>
        <li>{{ t('instance_export.restore.step_restore') }}</li>
      </ol>
      <code class="block mt-3 rounded code-surface px-3 py-2 text-xs break-all">php api/bin/archive-restore.php --file=export.zip --database=prazdna_migrovana_db --dry-run</code>
      <code class="block mt-2 rounded code-surface px-3 py-2 text-xs break-all">php api/bin/archive-restore.php --file=export.zip --database=prazdna_migrovana_db --restore --storage=cesta-k-novym-datum --documents</code>
      <p class="mt-3 text-xs text-neutral-500">{{ t('instance_export.restore.note') }}</p>
    </section>

    <RouterLink v-if="hasAccountingRetention" :to="{ name: 'accounting-retention' }"
      class="block bg-primary-50/50 border border-primary-100 rounded-lg p-4 mb-4 hover:border-primary-300 transition-colors">
      <span class="block text-sm font-medium text-primary-800">{{ t('instance_export.retention.title') }}</span>
      <span class="block text-xs text-primary-700 mt-1">{{ t('instance_export.retention.description') }}</span>
    </RouterLink>

    <!-- Zadání exportu -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
      <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('instance_export.new_export') }}</h2>

      <div class="flex flex-wrap gap-4 mb-4">
        <label v-for="part in visibleParts" :key="part"
          class="flex items-start gap-2 cursor-pointer max-w-xs">
          <input type="checkbox" class="mt-1" :checked="selectedParts.includes(part)"
            :disabled="isBusy" @change="togglePart(part)" />
          <span>
            <span class="text-sm font-medium text-neutral-800">{{ t(`instance_export.part.${part}`) }}</span>
            <span class="block text-xs text-neutral-500">{{ t(`instance_export.part_hint.${part}`) }}</span>
          </span>
        </label>
      </div>

      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
          <span class="block text-xs text-neutral-500 mb-1">{{ t('instance_export.date_from') }}</span>
          <DateInput v-model="dateFrom" :disabled="isBusy"
            class="border border-neutral-300 rounded px-2 py-1.5 text-sm" />
        </label>
        <label class="text-sm">
          <span class="block text-xs text-neutral-500 mb-1">{{ t('instance_export.date_to') }}</span>
          <DateInput v-model="dateTo" :disabled="isBusy"
            class="border border-neutral-300 rounded px-2 py-1.5 text-sm" />
        </label>
        <span class="text-xs text-neutral-500 pb-2 max-w-sm">{{ t('instance_export.range_hint') }}</span>
      </div>

      <div class="flex flex-wrap items-center gap-3 mt-4">
        <button @click="start" :disabled="starting || isBusy" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
          <span class="whitespace-nowrap">{{ starting ? t('instance_export.starting') : t('instance_export.start') }}</span>
        </button>
        <span v-if="isBusy" class="text-sm text-neutral-600">
          {{ t('instance_export.already_running') }}
        </span>
        <span v-else-if="overview" class="text-xs text-neutral-500">
          {{ t('instance_export.ttl_hint', { days: overview.ttl_days }) }}
        </span>
      </div>
    </div>

    <!-- Průběh běžícího exportu -->
    <div v-if="active && isBusy" class="bg-surface border border-primary-200 rounded-lg shadow-sm p-4 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <span class="text-sm font-medium text-neutral-800">
          {{ active.current_step || t('instance_export.preparing') }}
        </span>
        <button @click="cancel(active)" :disabled="active.cancel_requested" :class="btnOutlineSm('danger')">
          <span class="whitespace-nowrap">
            {{ active.cancel_requested ? t('instance_export.cancelling') : t('common.cancel') }}
          </span>
        </button>
      </div>
      <div class="h-2 bg-neutral-100 rounded overflow-hidden">
        <div class="h-full bg-primary-500 transition-all"
          :style="{ width: `${progressPercent ?? 8}%` }"></div>
      </div>
      <p class="text-xs text-neutral-500 mt-1">
        {{ progressPercent !== null
          ? `${active.processed_steps} / ${active.total_steps}`
          : t('instance_export.progress_unknown') }}
      </p>
    </div>

    <div v-if="loading && !overview" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="items.length === 0" boxed icon="archive"
      :title="t('instance_export.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('instance_export.col_created') }}</th>
              <th class="px-3 py-2 text-left font-medium w-28">{{ t('instance_export.col_status') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('instance_export.col_file') }}</th>
              <th class="px-3 py-2 text-right font-medium w-28">{{ t('instance_export.col_size') }}</th>
              <th class="px-3 py-2 text-left font-medium w-40">{{ t('instance_export.col_checksum') }}</th>
              <th class="px-3 py-2 text-left font-medium w-32">{{ t('instance_export.col_expires') }}</th>
              <th class="px-3 py-2 text-right font-medium w-56"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="job in items" :key="job.id">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(job.created_at) }}</td>
              <td class="px-3 py-2">
                <span class="inline-block px-2 py-0.5 rounded border text-xs whitespace-nowrap"
                  :class="statusClass(job.status)">
                  {{ t(`instance_export.status.${job.status}`) }}
                </span>
              </td>
              <td class="px-3 py-2 font-mono text-xs break-all">
                {{ job.result_name || '—' }}
                <span v-if="job.last_error" class="block text-danger-600 font-sans">{{ job.last_error }}</span>
                <span v-if="job.warning_count" class="block text-warning-700 font-sans">
                  {{ t('instance_export.warnings', { count: job.warning_count }) }}
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatBytes(job.size_bytes) }}</td>
              <td class="px-3 py-2 font-mono text-xs">
                <button v-if="job.sha256" @click="copyChecksum(job)" :title="job.sha256"
                  class="cursor-pointer hover:text-primary-700">{{ job.sha256.slice(0, 12) }}…</button>
                <span v-else>—</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap text-xs text-neutral-500">
                {{ job.expires_at ? formatDate(job.expires_at) : '—' }}
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button v-if="job.downloadable" @click="download(job)" :disabled="downloadingId === job.id"
                  class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium disabled:opacity-40">
                  {{ t('instance_export.download') }}
                </button>
                <button @click="openDetail(job)"
                  class="cursor-pointer text-xs text-neutral-600 hover:text-neutral-900 font-medium ml-3">
                  {{ t('instance_export.detail') }}
                </button>
                <button @click="remove(job)"
                  class="cursor-pointer text-xs text-danger-500 hover:text-danger-600 font-medium ml-3">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail běhu: co archiv obsahuje + průběh -->
    <div v-if="detail" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mt-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-medium text-neutral-700">
          {{ t('instance_export.detail_title', { id: detail.id }) }}
        </h2>
        <button @click="detail = null" :class="btnOutline('neutral')">
          <span class="whitespace-nowrap">{{ t('common.close') }}</span>
        </button>
      </div>

      <dl v-if="detail.summary" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm">
        <div>
          <dt class="text-xs text-neutral-500">{{ t('instance_export.sum_entries') }}</dt>
          <dd class="font-mono">{{ detail.summary.entries ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('instance_export.sum_tables') }}</dt>
          <dd class="font-mono">{{ detail.summary.tables }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('instance_export.sum_files') }}</dt>
          <dd class="font-mono">{{ detail.summary.files ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('instance_export.sum_encrypted') }}</dt>
          <dd>{{ detail.encrypted ? 'AES-256' : t('common.no') }}</dd>
        </div>
      </dl>

      <pre v-if="detail.log_text"
        class="bg-neutral-50 border border-neutral-200 rounded p-3 text-xs overflow-x-auto max-h-64 whitespace-pre-wrap">{{ detail.log_text }}</pre>
      <p v-else class="text-xs text-neutral-500">{{ t('instance_export.no_log') }}</p>
    </div>
  </div>
</template>

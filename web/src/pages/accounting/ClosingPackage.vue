<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import { reportsApi, type ClosingPackagePart, type ClosingPackagePreview, type ClosingPackageJob } from '@/api/reports'
import { closingApi, type ClosingState } from '@/api/closing'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()

const periodId = Number(route.params.id)

// Pořadí sestav v UI = pořadí, ve kterém jdou v uzávěrce po sobě.
//
// Musí obsahovat VŠECHNY části, které backend umí — nabídka je zároveň tím, co se
// vygeneruje. Chyběla tu příloha závěrky (`statement_notes`), takže povinná součást
// závěrky se z UI nikdy nevyžádala a balíček bez ní přesto hlásil „hotovo": stav se
// vyhodnocuje jen nad vyžádanými částmi.
const ALL_PARTS: ClosingPackagePart[] = [
  'balance_sheet', 'income_statement', 'statement_notes',
  'cash_flow', 'equity_changes',
  'general_ledger', 'trial_balance', 'journal', 'balance_inventory', 'open_items',
  'dph_book', 'income_tax', 'income_tax_report', 'income_tax_advances',
  'asset_inventory', 'saldo_over_1y', 'accruals',
]
const selected = ref<Set<ClosingPackagePart>>(new Set(ALL_PARTS))
const includeXlsx = ref(false)

const state = ref<ClosingState | null>(null)
const preview = ref<ClosingPackagePreview | null>(null)
const loading = ref(false)
const starting = ref(false)
const error = ref('')

const jobs = ref<ClosingPackageJob[]>([])
let pollTimer: ReturnType<typeof setInterval> | null = null

function countFor(part: ClosingPackagePart): number {
  return preview.value?.counts[part] ?? 0
}

async function loadState() {
  try {
    state.value = await closingApi.state(periodId)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.closingPackagePreview(periodId)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

const anyActive = computed(() => jobs.value.some(j => ['queued', 'running'].includes(j.status)))

async function loadJobs() {
  try {
    const prev = jobs.value
    jobs.value = await reportsApi.closingPackageJobs()
    for (const j of jobs.value) {
      const old = prev.find(p => p.id === j.id)
      if (old && ['queued', 'running'].includes(old.status) && old.status !== j.status) {
        if (j.status === 'completed') toast.success(t('accounting.closing_package.job.done'))
        else if (j.status === 'completed_with_warnings') toast.warning(t('accounting.closing_package.job.done_with_warnings', { count: j.failed_count }))
        else if (j.status === 'failed') toast.error(j.last_error || t('accounting.closing_package.download_failed'))
      }
    }
  } catch { /* ponech předchozí stav */ }
  syncPolling()
}

function syncPolling() {
  if (anyActive.value && !pollTimer) {
    pollTimer = setInterval(loadJobs, 2000)
  } else if (!anyActive.value && pollTimer) {
    clearInterval(pollTimer); pollTimer = null
  }
}

function toggle(part: ClosingPackagePart) {
  const next = new Set(selected.value)
  next.has(part) ? next.delete(part) : next.add(part)
  selected.value = next
}
function selectAll() { selected.value = new Set(ALL_PARTS) }
function selectNone() { selected.value = new Set() }

const selectedList = computed(() => ALL_PARTS.filter(p => selected.value.has(p) && countFor(p) > 0))
const hasDownloadableSelection = computed(() => selectedList.value.some(p => countFor(p) > 0))

function progressPct(j: ClosingPackageJob): number {
  if (!j.total_items || j.total_items <= 0) return 0
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
}
function isActive(j: ClosingPackageJob): boolean {
  return ['queued', 'running'].includes(j.status)
}

async function startPackage() {
  if (!hasDownloadableSelection.value || anyActive.value) return
  starting.value = true
  error.value = ''
  try {
    const r = await reportsApi.closingPackageStart(periodId, selectedList.value, includeXlsx.value)
    toast.success(t('accounting.closing_package.job.started', { jobId: r.job_id }))
    await loadJobs()
  } catch (e) {
    error.value = apiErrorMessage(e)
    toast.error(error.value)
  } finally {
    starting.value = false
  }
}

async function cancelJob(j: ClosingPackageJob) {
  try {
    await reportsApi.closingPackageCancel(j.id)
    toast.success(t('accounting.closing_package.job.cancel_requested'))
    await loadJobs()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function deleteJob(j: ClosingPackageJob) {
  if (!confirm(t('accounting.closing_package.job.delete_confirm'))) return
  try {
    await reportsApi.closingPackageDeleteJob(j.id)
  } catch (e) {
    toast.error(apiErrorMessage(e)); return
  }
  await loadJobs()
}

function isDownloadable(j: ClosingPackageJob): boolean {
  return j.status === 'completed' || j.status === 'completed_with_warnings'
}
function downloadJob(j: ClosingPackageJob) {
  if (!isDownloadable(j)) return
  window.open(reportsApi.closingPackageDownloadUrl(j.id), '_blank')
}

function fmtSize(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} kB`
  return `${bytes} B`
}
function statusClass(j: ClosingPackageJob): string {
  switch (j.status) {
    case 'completed': return 'bg-success-100 text-success-700'
    case 'completed_with_warnings': return 'bg-warning-100 text-warning-700'
    case 'failed':    return 'bg-danger-100 text-danger-700'
    case 'cancelled': return 'bg-neutral-200 text-neutral-600'
    case 'running':   return 'bg-primary-100 text-primary-700'
    default:          return 'bg-warning-100 text-warning-700'
  }
}

onMounted(async () => {
  await Promise.all([loadState(), loadPreview()])
  await loadJobs()
})
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div class="flex items-center justify-between mb-1 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.closing_package.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.closing_package.subtitle') }}</p>
        <p v-if="state" class="text-sm text-neutral-600 mt-1">
          {{ state.period.fiscal_year }} · {{ formatDate(state.period.starts_on) }} – {{ formatDate(state.period.ends_on) }}
        </p>
      </div>
      <RouterLink :to="{ name: 'accounting-period-closing', params: { id: periodId } }" class="text-sm text-neutral-500 hover:text-neutral-700">
        {{ t('accounting.closing_package.back_to_closing') }}
      </RouterLink>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-600 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <p v-if="preview?.accounting_framework === 'cz_504'" class="rounded-md border border-primary-200 bg-primary-50 p-3 text-sm text-primary-700">{{ t('accounting.statement504.package_hint') }}</p>

    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-5">
      <!-- Výběr sestav -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-neutral-700">{{ t('accounting.closing_package.parts_label') }}</label>
          <div class="flex items-center gap-3 text-xs">
            <button type="button" @click="selectAll" :disabled="anyActive" class="cursor-pointer text-primary-600 hover:text-primary-700 disabled:text-neutral-300">{{ t('accounting.closing_package.select_all') }}</button>
            <span class="text-neutral-300">|</span>
            <button type="button" @click="selectNone" :disabled="anyActive" class="cursor-pointer text-neutral-500 hover:text-neutral-700 disabled:text-neutral-300">{{ t('accounting.closing_package.select_none') }}</button>
          </div>
        </div>

        <div class="space-y-2">
          <label
            v-for="part in ALL_PARTS"
            :key="part"
            class="flex items-center gap-3 p-3 border rounded-md transition"
            :class="[
              countFor(part) === 0 || anyActive ? 'opacity-60' : 'cursor-pointer hover:bg-neutral-50',
              selected.has(part) && countFor(part) > 0 ? 'border-primary-400 bg-primary-50/60' : 'border-neutral-200',
            ]"
          >
            <input
              type="checkbox"
              class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="selectedList.includes(part)"
              :disabled="countFor(part) === 0 || anyActive"
              @change="toggle(part)"
            />
            <span class="text-sm font-medium text-neutral-800 flex-1">
              {{ t('accounting.closing_package.parts.' + part) }}
              <!-- Příloha závěrky má vlastní editor sekcí — bez odkazu odsud se k němu
                   uživatel nedostal a neúplná příloha nešla doplnit (ani u uzavřeného roku). -->
              <RouterLink v-if="part === 'statement_notes'"
                :to="{ name: 'accounting-statement-notes', params: { id: periodId } }"
                class="ml-2 text-xs font-normal text-primary-600 hover:text-primary-700"
                @click.stop>
                {{ t('accounting.closing_package.edit_notes') }} →
              </RouterLink>
            </span>
            <span v-if="!loading"
              class="text-xs font-mono px-2 py-0.5 rounded"
              :class="countFor(part) > 0 ? 'bg-neutral-100 text-neutral-600' : 'bg-neutral-50 text-neutral-400'">
              {{ countFor(part) > 0 ? t('reports.monthly_export.available_count', { count: countFor(part) }) : '—' }}
            </span>
            <span v-else class="text-xs text-neutral-300">…</span>
          </label>
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input type="checkbox" v-model="includeXlsx" :disabled="anyActive"
          class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('accounting.closing_package.include_xlsx') }}
      </label>

      <!-- Start -->
      <div class="flex items-center justify-end gap-3 pt-1">
        <span v-if="anyActive" class="text-xs text-neutral-500">{{ t('accounting.closing_package.job.in_progress') }}</span>
        <span v-else-if="!hasDownloadableSelection && !loading" class="text-xs text-neutral-500">
          {{ selectedList.length === 0 ? t('accounting.closing_package.no_selection') : t('reports.monthly_export.empty_hint') }}
        </span>
        <button
          type="button"
          @click="startPackage"
          :disabled="starting || loading || anyActive || !hasDownloadableSelection"
          :class="btnFilled('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
          {{ t('accounting.closing_package.job.start') }}
        </button>
      </div>
    </div>

    <!-- Historie balíčků -->
    <div v-if="jobs.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 text-sm font-medium text-neutral-700">
        {{ t('accounting.closing_package.job.history') }}
      </div>
      <ul class="divide-y divide-neutral-100">
        <li v-for="j in jobs" :key="j.id" class="px-5 py-3">
          <div class="flex items-center gap-3 flex-wrap">
            <span class="text-xs font-semibold px-2 py-0.5 rounded" :class="statusClass(j)">
              {{ t('accounting.closing_package.job.status.' + j.status) }}
            </span>
            <span class="text-sm font-medium text-neutral-800">
              {{ (j.params as { fiscal_year?: number })?.fiscal_year }}
            </span>
            <span v-if="isDownloadable(j)" class="text-xs text-neutral-500">
              {{ t('accounting.closing_package.job.ready', { count: j.created_count }) }}<template v-if="j.result_size"> · {{ fmtSize(j.result_size) }}</template>
            </span>
            <span v-else-if="isActive(j) && j.current_step" class="text-xs text-neutral-500">{{ j.current_step }}</span>

            <div class="ml-auto flex items-center gap-2">
              <button v-if="isActive(j)" type="button" @click="cancelJob(j)" :disabled="j.cancel_requested"
                class="cursor-pointer px-2.5 h-8 text-xs border border-neutral-300 text-neutral-700 rounded-md hover:bg-neutral-100 disabled:opacity-50">
                {{ t('accounting.closing_package.job.cancel') }}
              </button>
              <button v-if="isDownloadable(j)" type="button" @click="downloadJob(j)"
                class="cursor-pointer px-3 h-8 text-xs bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                {{ t('reports.monthly_export.download') }}
              </button>
              <button v-if="!isActive(j)" type="button" @click="deleteJob(j)" :title="t('common.delete')"
                class="cursor-pointer px-2 h-8 text-neutral-400 hover:text-danger-600 rounded-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>

          <div v-if="isActive(j)" class="mt-2">
            <div class="h-1.5 w-full rounded-full bg-neutral-200 overflow-hidden">
              <div class="h-full bg-primary-500 transition-all" :style="{ width: progressPct(j) + '%' }"></div>
            </div>
            <div class="text-[11px] text-neutral-400 mt-1 font-mono">{{ j.processed }}<span v-if="j.total_items"> / {{ j.total_items }}</span></div>
          </div>
          <!-- EP-6: „hotovo s upozorněními" — povinné jádro OK, ale doplňkové části selhaly.
               Uživatel vidí počet selhání a odkaz na README/manifest JEŠTĚ PŘED stažením. -->
          <div v-else-if="j.status === 'completed_with_warnings'"
            class="mt-1.5 text-xs text-warning-700 bg-warning-50 border border-warning-500/30 rounded-md px-2 py-1.5">
            {{ t('accounting.closing_package.job.warnings_notice', { count: j.failed_count }) }}
            <RouterLink :to="{ name: 'accounting-statement-notes', params: { id: periodId } }"
              class="block mt-1 text-primary-600 hover:text-primary-700">
              {{ t('accounting.closing_package.notes_incomplete_hint') }} →
            </RouterLink>
          </div>
          <div v-else-if="j.status === 'failed' && j.last_error" class="mt-1.5 text-xs text-danger-600">{{ j.last_error }}</div>
        </li>
      </ul>
    </div>
  </div>
</template>

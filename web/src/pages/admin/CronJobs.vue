<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type CronJob, type CronJobHealth, type CronInstallContext, type CronScheduleContext, type CronScheduleMode } from '@/api/admin'
import { useToast } from '@/composables/useToast'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const jobs = ref<CronJob[]>([])
const install = ref<CronInstallContext | null>(null)
const schedule = ref<CronScheduleContext | null>(null)
const serverTime = ref<string>('')
const loading = ref(false)
const expanded = ref<Record<string, boolean>>({})
const running = ref<Record<string, boolean>>({})

// Poslední pokyn po přepnutí režimu — drží se, dokud si ho admin nezavře.
// Přepnutí samo nic nepřeplánuje, takže tenhle text je jediné, co ho dovede
// k tomu, aby plán skutečně změnil.
const modeNotice = ref<string | null>(null)
const switchingMode = ref(false)

const scheduleMode = computed<CronScheduleMode>(() => schedule.value?.mode ?? 'individual')
const isDispatcherMode = computed(() => scheduleMode.value === 'dispatcher')

async function load(signal?: AbortSignal) {
  loading.value = true
  try {
    const r = await adminApi.cronJobs(signal)
    jobs.value = r.jobs
    install.value = r.install ?? null
    schedule.value = r.schedule ?? null
    serverTime.value = r.server_time
  } finally {
    loading.value = false
  }
}

async function switchMode(mode: CronScheduleMode) {
  if (switchingMode.value || mode === scheduleMode.value) return
  if (!window.confirm(t('cron_jobs.mode_confirm', { mode: t(`cron_jobs.mode_${mode}`) }))) return

  switchingMode.value = true
  try {
    const r = await adminApi.setCronScheduleMode(mode)
    modeNotice.value = r.next_step
    toast.success(t('cron_jobs.mode_saved', { mode: t(`cron_jobs.mode_${r.mode}`) }))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('cron_jobs.mode_failed'))
  } finally {
    switchingMode.value = false
  }
}

const polling = useSessionAwarePolling(signal => load(signal), 60_000)

function toggle(script: string) {
  expanded.value[script] = !expanded.value[script]
}

async function runNow(script: string) {
  if (running.value[script]) return
  if (!window.confirm(t('cron_jobs.run_now_confirm', { script }))) return
  running.value[script] = true
  try {
    await adminApi.runCronJob(script)
    toast.success(t('cron_jobs.run_now_started', { script }))
    // Refresh hned a pak ještě několikrát, aby se chytl finish běhu.
    setTimeout(() => { if (polling.active.value) void load() }, 1500)
    setTimeout(() => { if (polling.active.value) void load() }, 5000)
    setTimeout(() => { if (polling.active.value) void load() }, 15000)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('cron_jobs.run_now_failed', { script }))
  } finally {
    running.value[script] = false
  }
}

function fmtTime(iso: string | null): string {
  if (!iso) return '—'
  return iso.replace('T', ' ').slice(0, 19)
}

function fmtAge(seconds: number | null): string {
  if (seconds === null) return '—'
  if (seconds < 90) return t('cron_jobs.ago_seconds', { n: seconds })
  const minutes = Math.floor(seconds / 60)
  if (minutes < 90) return t('cron_jobs.ago_minutes', { n: minutes })
  const hours = Math.floor(minutes / 60)
  if (hours < 48) return t('cron_jobs.ago_hours', { n: hours })
  return t('cron_jobs.ago_days', { n: Math.floor(hours / 24) })
}

function fmtFreq(recommended: string): string {
  const key = `cron_jobs.freq_${recommended}`
  const translated = t(key)
  return translated === key ? recommended : translated
}

function fmtDuration(ms: number | null): string {
  if (ms === null) return '—'
  if (ms < 1000) return `${ms} ms`
  return `${(ms / 1000).toFixed(1)} s`
}

interface NonImportedItem { supplier_id?: number | null; file: string; status: string; reason?: string }

function getNonImported(report: unknown): NonImportedItem[] {
  if (!report || typeof report !== 'object') return []
  const arr = (report as Record<string, unknown>).non_imported
  return Array.isArray(arr) ? (arr as NonImportedItem[]) : []
}
function getNonImportedCount(report: unknown): number {
  if (!report || typeof report !== 'object') return 0
  const r = report as Record<string, unknown>
  return (typeof r.non_imported_count === 'number' ? r.non_imported_count : 0) || getNonImported(report).length
}
function isNonImportedTruncated(report: unknown): boolean {
  return !!(report && typeof report === 'object' && (report as Record<string, unknown>).non_imported_truncated === true)
}

function reportSummary(report: unknown): string {
  // Stringify report bez non_imported (která je renderována zvlášť) — pro stručný 1-line přehled
  if (!report || typeof report !== 'object') return String(report ?? '')
  const r = { ...(report as Record<string, unknown>) }
  delete r.non_imported
  return JSON.stringify(r)
}

function healthBadgeClass(h: CronJobHealth): string {
  switch (h) {
    case 'ok': return 'bg-success-50 text-success-600'
    // Nečinnost není varování — je to normální provozní stav gatované úlohy.
    case 'idle': return 'bg-neutral-100 text-neutral-600'
    // Čerstvá instalace ještě nestihla ani jednu periodu téhle úlohy — "nikdy
    // neběželo" je tu očekávaný stav, ne poplach (viz never_ran níž).
    case 'pending': return 'bg-neutral-100 text-neutral-600'
    // Vypnutá konfigurací (cron.disabled_jobs) je záměr admina/spravovaného
    // hostingu, ne chyba — stejná neutrální barva jako idle/pending.
    case 'disabled': return 'bg-neutral-100 text-neutral-600'
    case 'overdue': return 'bg-warning-50 text-warning-600'
    case 'failing':
    case 'overdue_and_failing':
    // Instalace už periodu úlohy přerostla a heartbeat pořád chybí — skutečný
    // nález (viz issue #6), ne prázdný sloupec. Musí být vidět na první pohled.
    case 'never_ran': return 'bg-danger-50 text-danger-500'
  }
}

function healthLabel(h: CronJobHealth): string {
  return t(`cron_jobs.health_${h}`)
}

function healthTooltip(j: CronJob): string {
  if (j.health === 'idle') return t('cron_jobs.tooltip_idle', { script: schedule.value?.dispatcher_script ?? 'cron-dispatch' })
  if (j.health === 'pending') return t('cron_jobs.tooltip_pending', { hours: j.max_age_hours })
  // Vlastní text — nesmí znít jako "nespustila se", ale jako "vypnul ji admin".
  if (j.health === 'disabled') return t('cron_jobs.tooltip_disabled')
  if (j.health === 'overdue' || j.health === 'overdue_and_failing') {
    return t('cron_jobs.tooltip_overdue', { hours: j.max_age_hours })
  }
  if (j.health === 'failing') return t('cron_jobs.tooltip_failing')
  if (j.health === 'never_ran') return t('cron_jobs.tooltip_never_ran')
  return ''
}

// Pending, idle a disabled nejsou problém — jsou to normální/záměrné stavy.
const hasProblems = computed(() => jobs.value.some(j =>
  j.health !== 'ok' && j.health !== 'idle' && j.health !== 'pending' && j.health !== 'disabled'
))

// ── Návod na naplánování úloh ───────────────────────────────────────────────
// Skládá se z KATALOGU (frekvence) a ze SKUTEČNÝCH cest běžícího nasazení
// (`install`), aby šel příkaz zkopírovat bez přepisování. Sbaleno, protože se
// to řeší jednou při instalaci.

type Platform = 'linux' | 'windows' | 'docker'

const platform = ref<Platform>('linux')
watch(install, (i) => {
  if (!i) return
  platform.value = i.is_docker ? 'docker' : (i.os_family === 'Windows' ? 'windows' : 'linux')
}, { immediate: true })

/** `cron-generate-recurring-invoices` → `MyUcto Generate Recurring Invoices` */
function taskName(script: string): string {
  const words = script.replace(/^cron-/, '').split('-')
    .map(w => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
  return `MyUcto ${words}`
}

/** Běží server na platformě, kterou má uživatel zvolenou v záložce? */
const platformIsLive = computed<boolean>(() => {
  const i = install.value
  if (!i) return false
  if (i.is_docker) return platform.value === 'docker'
  return platform.value === (i.os_family === 'Windows' ? 'windows' : 'linux')
})

/**
 * Adresář `cmd/` pro zvolenou platformu. Skutečnou cestu serveru použijeme JEN
 * na jeho vlastní platformě — přeložit `C:\inetpub\…` na lomítka a vydávat to za
 * cestu pro crontab by byl nesmysl, který se zkopíruje a nefunguje. Pro cizí
 * platformu radši zjevný vzorový kořen, u kterého je vidět, že se má přepsat.
 */
const setupCmdDir = computed<string>(() => {
  const i = install.value
  if (!i) return ''
  if (platformIsLive.value) return i.cmd_dir
  return platform.value === 'windows'
    ? 'C:\\inetpub\\wwwroot\\myucto.cz\\cmd'
    : '/var/www/myucto.cz/cmd'
})

const setupLogDir = computed<string>(() =>
  platformIsLive.value && install.value ? install.value.log_dir : '/data/log/cron'
)

const setupCommands = computed<string>(() => {
  if (!install.value || jobs.value.length === 0) return ''
  const dir = setupCmdDir.value

  // V režimu dispatcheru se registruje JEN plánovač. Vypisovat i jednotlivé
  // úlohy by přímo navádělo k tomu je zaregistrovat taky — a pak by běžely
  // dvakrát (duplicitní faktury z pravidelné fakturace, dvojí zaúčtování mezd).
  const toSchedule = jobs.value.filter(j => j.scheduled_directly !== false)

  if (platform.value === 'windows') {
    // Jednořádkově — `^` (pokračovací znak cmd.exe) by se v PowerShellu rozbil,
    // a admin si příkaz stejně kopíruje po jednom.
    return toSchedule.map(j =>
      `schtasks /create /tn "${taskName(j.script)}" /tr "${dir}\\${j.script}.cmd" ${j.windows_schtasks} /ru SYSTEM`
    ).join('\n')
  }

  if (platform.value === 'docker') {
    return [
      '# Cron je součástí image; plán se generuje z katalogu při startu kontejneru',
      '# podle nastaveného režimu — ručně se nic neplánuje, jen se ověřuje:',
      'docker compose exec app cat /etc/cron.d/myucto',
      `docker compose exec app ls -l ${setupLogDir.value}`,
      '',
      '# Po přepnutí režimu plánování stačí restart (plán se vygeneruje znovu):',
      'docker compose restart app',
      '',
      '# Po změně frekvence v CronCatalog.php se image musí přebuildit:',
      'docker compose build app && docker compose up -d app',
    ].join('\n')
  }

  return [
    '# crontab -e  (uživatel s právem zápisu do log/ a storage/)',
    ...toSchedule.map(j => `${j.linux_cron}\t${dir}/${j.script}.sh`),
  ].join('\n')
})

const copied = ref(false)
async function copySetup() {
  try {
    await navigator.clipboard.writeText(setupCommands.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    toast.error(t('cron_jobs.setup_copy_failed'))
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('cron_jobs.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('cron_jobs.subtitle') }}</p>
      <p class="text-xs text-neutral-500 mt-2">
        <i18n-t keypath="cron_jobs.setup_hint" tag="span">
          <template #link>
            <a href="/manual?ch=05_Po_instalaci#55-cron-skripty" target="_blank" rel="noopener" class="text-primary-600 hover:underline">{{ t('cron_jobs.setup_link') }}</a>
          </template>
        </i18n-t>
      </p>
    </div>

    <!-- Režim plánování -->
    <div v-if="schedule" class="mb-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-sm font-semibold text-neutral-900">{{ t('cron_jobs.mode_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-0.5 max-w-2xl">{{ t('cron_jobs.mode_subtitle') }}</p>
        </div>
        <!-- ⚠️ U spravovaného provozu se přepínač NENABÍZÍ. Plánování drží
             provozovatel jedinou položkou `cron-dispatch`; přepnutí na
             „jednotlivé úlohy" znamená, že dispatcher skončí bez práce
             a nespustí se NIC — a heartbeat u toho tiká dál, takže si toho
             instalace ani nevšimne. Server to odmítá taky, tohle je jen
             poctivé UI. -->
        <p v-if="auth.isManagedInstallation" class="text-xs text-neutral-600 shrink-0 max-w-sm">
          {{ t('cron_jobs.mode_managed') }}
        </p>
        <div v-else class="flex gap-2 shrink-0" role="radiogroup" :aria-label="t('cron_jobs.mode_title')">
          <button
            v-for="m in schedule.modes"
            :key="m"
            type="button"
            role="radio"
            :aria-checked="scheduleMode === m"
            :disabled="switchingMode"
            class="px-3 py-1.5 text-xs font-medium rounded border transition-colors disabled:opacity-50"
            :class="scheduleMode === m
              ? 'bg-primary-600 border-primary-600 text-white'
              : 'bg-surface border-neutral-300 text-neutral-700 hover:bg-neutral-50'"
            @click="switchMode(m)"
          >
            {{ t(`cron_jobs.mode_${m}`) }}
          </button>
        </div>
      </div>

      <p class="text-xs text-neutral-600 mt-3">
        {{ isDispatcherMode
          ? t('cron_jobs.mode_dispatcher_desc', { script: schedule.dispatcher_script, count: schedule.individual_count })
          : t('cron_jobs.mode_individual_desc', { count: schedule.individual_count }) }}
      </p>

      <!-- Zápis do DB sám nic nepřeplánuje — bez tohohle upozornění by admin
           přepnul režim a divil se, že se nic nezměnilo. -->
      <div v-if="modeNotice" class="mt-3 flex items-start gap-2 rounded border border-warning-300 bg-warning-50 px-3 py-2">
        <span class="text-warning-600 shrink-0 text-sm leading-5">⚠</span>
        <p class="text-xs text-warning-800 flex-1">{{ modeNotice }}</p>
        <button type="button" class="text-xs text-warning-700 hover:underline shrink-0" @click="modeNotice = null">
          {{ t('common.close') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !jobs.length" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop tabulka -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.script') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.recommended') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.last_run') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.duration') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.last_24h') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('cron_jobs.health') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
              <th class="px-3 py-2 text-right font-medium w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="j in jobs" :key="j.script">
              <tr class="hover:bg-neutral-50 align-top cursor-pointer" @click="toggle(j.script)">
                <td class="px-3 py-2">
                  <div class="font-mono text-xs font-medium text-neutral-900">{{ j.script }}</div>
                  <div class="flex gap-1.5 mt-0.5">
                    <span v-if="j.critical" class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-primary-50 text-primary-600 leading-none">{{ t('cron_jobs.critical_hint') }}</span>
                    <span v-if="j.weekdays_only" class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 leading-none">{{ t('cron_jobs.weekdays_only_hint') }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600">
                  <div>{{ fmtFreq(j.recommended) }}</div>
                  <code class="text-[10px] text-neutral-400 font-mono">{{ j.linux_cron }}</code>
                </td>
                <td class="px-3 py-2 text-xs whitespace-nowrap">
                  <div v-if="j.last_started_at" class="text-neutral-900 font-mono">{{ fmtTime(j.last_started_at) }}</div>
                  <div v-else class="text-neutral-400 italic">{{ t('cron_jobs.no_runs_yet') }}</div>
                  <div v-if="j.age_sec_since_ok !== null" class="text-[10px] text-neutral-500 mt-0.5">{{ fmtAge(j.age_sec_since_ok) }}</div>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ fmtDuration(j.last_duration_ms) }}</td>
                <td class="px-3 py-2 text-xs whitespace-nowrap">
                  <span v-if="j.counts_24h.total === 0" class="text-neutral-400">—</span>
                  <template v-else>
                    <span class="text-success-600 font-mono">{{ j.counts_24h.ok }} ✓</span>
                    <span v-if="j.counts_24h.error > 0" class="text-danger-500 font-mono ml-1.5">{{ j.counts_24h.error }} ✗</span>
                  </template>
                </td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="text-xs px-2 py-0.5 rounded font-medium" :class="healthBadgeClass(j.health)" :title="healthTooltip(j)">
                    {{ healthLabel(j.health) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap" @click.stop>
                  <button
                    type="button"
                    :class="btnOutline('warning')"
                    :disabled="running[j.script]"
                    @click="runNow(j.script)"
                  >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
                    {{ t('cron_jobs.run_now') }}
                  </button>
                </td>
                <td class="px-3 py-2 text-right">
                  <svg class="w-4 h-4 text-neutral-400 inline-block transition" :class="{ 'rotate-180': expanded[j.script] }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </td>
              </tr>
              <tr v-if="expanded[j.script]" class="bg-neutral-50/60">
                <td colspan="8" class="px-3 py-3">
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                    <div>
                      <span class="text-neutral-500">Linux cron: </span>
                      <code class="font-mono text-neutral-700">{{ j.linux_cron }}</code>
                    </div>
                    <div>
                      <span class="text-neutral-500">Windows schtasks: </span>
                      <code class="font-mono text-neutral-700">{{ j.windows_schtasks }}</code>
                    </div>
                    <div v-if="j.last_host">
                      <span class="text-neutral-500">{{ t('cron_jobs.host') }}: </span>
                      <code class="font-mono text-neutral-700">{{ j.last_host }}</code>
                    </div>
                    <div v-if="j.last_ok_started_at">
                      <span class="text-neutral-500">{{ t('cron_jobs.last_ok') }}: </span>
                      <span class="font-mono text-neutral-700">{{ fmtTime(j.last_ok_started_at) }}</span>
                    </div>
                  </div>
                  <div
                    v-if="j.health === 'idle' || j.health === 'pending' || j.health === 'disabled'"
                    class="mt-2 text-xs text-neutral-500"
                  >
                    {{ healthTooltip(j) }}
                  </div>
                  <div
                    v-else-if="j.health === 'overdue'"
                    class="mt-2 text-xs text-warning-600 font-medium"
                  >
                    ⚠ {{ healthTooltip(j) }}
                  </div>
                  <div
                    v-else-if="j.health === 'never_ran' || j.health === 'failing' || j.health === 'overdue_and_failing'"
                    class="mt-2 text-xs text-danger-600 font-medium"
                  >
                    ⚠ {{ healthTooltip(j) }}
                  </div>
                  <div v-if="j.last_message" class="mt-2 text-xs">
                    <span class="text-neutral-500">{{ t('cron_jobs.message_label') }}: </span>
                    <span class="font-mono text-danger-600 break-all">{{ j.last_message }}</span>
                  </div>
                  <div v-if="j.last_report" class="mt-2 text-xs">
                    <span class="text-neutral-500">{{ t('cron_jobs.report_label') }}: </span>
                    <code class="font-mono text-neutral-700 break-all">{{ reportSummary(j.last_report) }}</code>
                  </div>
                  <!-- Failed/skipped items list (e.g. cron-scan-purchase-inbox) -->
                  <details v-if="getNonImported(j.last_report).length > 0" class="mt-2 text-xs">
                    <summary class="cursor-pointer text-danger-600 hover:text-danger-700 select-none">
                      {{ t('cron_jobs.non_imported_label', { n: getNonImportedCount(j.last_report) }) }}
                    </summary>
                    <ul class="mt-1.5 space-y-1 max-h-48 overflow-y-auto bg-neutral-50 border border-neutral-200 rounded p-2 font-mono">
                      <li v-for="(item, idx) in getNonImported(j.last_report)" :key="idx" class="text-[11px]">
                        <span class="inline-block px-1.5 rounded text-[10px] uppercase font-semibold mr-1.5"
                          :class="item.status === 'failed' ? 'bg-danger-100 text-danger-700' : 'bg-warning-100 text-warning-700'">
                          {{ item.status }}
                        </span>
                        <span class="text-neutral-700">{{ item.file }}</span>
                        <span v-if="item.reason" class="text-neutral-500"> — {{ item.reason }}</span>
                      </li>
                    </ul>
                    <div v-if="isNonImportedTruncated(j.last_report)" class="mt-1 text-neutral-400">
                      … {{ t('cron_jobs.list_truncated') }}
                    </div>
                  </details>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="j in jobs" :key="`m-${j.script}`" class="p-3 space-y-1.5">
          <div class="flex items-start justify-between gap-2">
            <div class="font-mono text-xs font-medium break-all">{{ j.script }}</div>
            <span class="text-xs px-2 py-0.5 rounded font-medium shrink-0" :class="healthBadgeClass(j.health)" :title="healthTooltip(j)">{{ healthLabel(j.health) }}</span>
          </div>
          <div class="text-xs text-neutral-600">{{ fmtFreq(j.recommended) }}</div>
          <div class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_run') }}</span>
            <span v-if="j.last_started_at" class="font-mono">{{ fmtTime(j.last_started_at) }}</span>
            <span v-else class="text-neutral-400 italic">{{ t('cron_jobs.no_runs_yet') }}</span>
          </div>
          <div v-if="j.age_sec_since_ok !== null" class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_ok') }}</span>
            <span>{{ fmtAge(j.age_sec_since_ok) }}</span>
          </div>
          <div v-if="j.counts_24h.total > 0" class="flex items-baseline justify-between gap-2 text-xs">
            <span class="text-neutral-500">{{ t('cron_jobs.last_24h') }}</span>
            <span class="font-mono">
              <span class="text-success-600">{{ j.counts_24h.ok }} ✓</span>
              <span v-if="j.counts_24h.error > 0" class="text-danger-500 ml-1.5">{{ j.counts_24h.error }} ✗</span>
            </span>
          </div>
          <div v-if="j.last_message" class="text-xs text-danger-600 font-mono break-all">{{ j.last_message }}</div>
          <div class="pt-1">
            <button
              type="button"
              :class="btnOutline('warning')"
              :disabled="running[j.script]"
              @click="runNow(j.script)"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
              {{ t('cron_jobs.run_now') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <p v-if="!hasProblems && jobs.length" class="mt-3 text-xs text-success-600">✓ {{ t('cron_jobs.all_ok') }}</p>

    <!-- Jak úlohy naplánovat — sbaleno, řeší se jednou při instalaci. -->
    <details v-if="install && jobs.length" class="mt-6 rounded-lg border border-neutral-200">
      <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium">
        {{ t('cron_jobs.setup_title') }}
        <span class="ml-2 text-xs font-normal text-neutral-500">{{ t('cron_jobs.setup_paths_hint') }}</span>
      </summary>

      <div class="border-t border-neutral-200 px-4 py-3 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <button
            v-for="p in (['linux', 'windows', 'docker'] as const)"
            :key="p"
            type="button"
            class="text-xs px-2.5 py-1 rounded border"
            :class="platform === p
              ? 'border-primary-500 bg-primary-50 text-primary-700 font-medium'
              : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
            @click="platform = p"
          >
            {{ t(`cron_jobs.setup_platform_${p}`) }}
          </button>
          <button type="button" :class="[btnOutline('neutral'), 'ml-auto']" @click="copySetup">
            {{ copied ? t('cron_jobs.setup_copied') : t('cron_jobs.setup_copy') }}
          </button>
        </div>

        <p class="text-xs text-neutral-500">{{ t(`cron_jobs.setup_intro_${platform}`) }}</p>
        <p v-if="!platformIsLive" class="text-xs text-warning-600">{{ t('cron_jobs.setup_foreign_platform') }}</p>

        <!-- Vodorovný scroll patří bloku s příkazy, ne stránce. -->
        <pre class="text-xs font-mono bg-neutral-50 border border-neutral-200 rounded p-3 overflow-x-auto whitespace-pre">{{ setupCommands }}</pre>

        <dl class="text-xs text-neutral-500 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1">
          <dt>{{ t('cron_jobs.setup_project_root') }}</dt>
          <dd class="font-mono break-all">{{ install.project_root }}</dd>
          <dt>{{ t('cron_jobs.setup_log_dir') }}</dt>
          <dd class="font-mono break-all">{{ install.log_dir }}</dd>
          <dt>{{ t('cron_jobs.setup_php_binary') }}</dt>
          <dd class="font-mono break-all">{{ install.php_binary }}</dd>
          <template v-if="install.data_dir">
            <dt>MYINVOICE_DATA_DIR</dt>
            <dd class="font-mono break-all">{{ install.data_dir }}</dd>
          </template>
        </dl>

        <p class="text-xs text-neutral-500">{{ t('cron_jobs.setup_note') }}</p>
      </div>
    </details>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { ICONS } from '@/components/ui/buttonStyles'
import {
  dismissMaintenanceNotice,
  dismissedMaintenanceNotices,
  refreshMaintenanceNotices,
  refreshedMaintenanceNotices,
  visibleMaintenanceNotices,
  type MaintenanceNotice,
} from '@/api/maintenanceNotice'

/**
 * Pruh s ohlášením plánované údržby.
 *
 * Na rozdíl od {@link InstanceCriticalBar} jde zavřít: je to jen ohlášení,
 * ne závada, a uživatel ho má vidět, ne s ním bojovat. Zavření platí pro danou
 * verzi hlášky. Když provozovatel termín posune nebo text upraví, hláška má nový
 * klíč a ukáže se znovu.
 *
 * `poll` zapíná obnovu každých 10 minut. Běží jen v hlavním layoutu, jeden
 * dotaz za 10 minut stránky nezatíží; přihlašovací stránce stačí stav ze startu.
 */
const props = withDefaults(defineProps<{ poll?: boolean }>(), { poll: false })

const REFRESH_EVERY_MS = 10 * 60 * 1000
const CLOCK_TICK_MS = 60 * 1000

const { t, locale } = useI18n()
const auth = useAuthStore()

const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | null = null
let refresher: ReturnType<typeof setInterval> | null = null

const notices = computed<MaintenanceNotice[]>(() => visibleMaintenanceNotices(
  refreshedMaintenanceNotices.value ?? auth.setupStatus?.maintenance_notices ?? [],
  dismissedMaintenanceNotices.value,
  now.value,
))

const TONE: Record<MaintenanceNotice['severity'], string> = {
  info: 'border-primary-200 bg-primary-50 text-primary-800',
  warning: 'border-warning-300 bg-warning-50 text-warning-800',
  danger: 'border-danger-300 bg-danger-50 text-danger-700',
}

function isRunning(notice: MaintenanceNotice): boolean {
  return Date.parse(notice.starts_at) <= now.value
}

function message(notice: MaintenanceNotice): string {
  return locale.value.startsWith('en') ? notice.message.en : notice.message.cs
}

/** „20. 11. 2026 21:00–23:00", u vícedenní údržby obě data celá. */
function range(notice: MaintenanceNotice): string {
  const start = new Date(notice.starts_at)
  const end = new Date(notice.ends_at)
  const date = new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'numeric', year: 'numeric' })
  const time = new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' })
  if (start.toDateString() === end.toDateString()) {
    return `${date.format(start)} ${time.format(start)}–${time.format(end)}`
  }

  return `${date.format(start)} ${time.format(start)} – ${date.format(end)} ${time.format(end)}`
}

onMounted(() => {
  clock = setInterval(() => { now.value = Date.now() }, CLOCK_TICK_MS)
  if (props.poll) {
    refresher = setInterval(() => { void refreshMaintenanceNotices() }, REFRESH_EVERY_MS)
  }
})

onBeforeUnmount(() => {
  if (clock) clearInterval(clock)
  if (refresher) clearInterval(refresher)
})
</script>

<template>
  <div
    v-for="notice in notices"
    :key="notice.key"
    class="mb-5 rounded-lg border px-4 py-3 text-sm flex items-start gap-3"
    :class="TONE[notice.severity]"
    role="status"
    data-maintenance-notice
  >
    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" />
    </svg>
    <div class="min-w-0 flex-1">
      <p class="font-semibold">
        {{ isRunning(notice) ? t('maintenance_notice.title_running') : t('maintenance_notice.title') }}
        <span class="font-normal whitespace-nowrap">{{ range(notice) }}</span>
      </p>
      <p class="mt-0.5 whitespace-pre-line break-words">{{ message(notice) }}</p>
    </div>
    <button
      type="button"
      class="shrink-0 rounded-md p-1 opacity-70 hover:opacity-100 hover:bg-black/5"
      :title="t('maintenance_notice.dismiss')"
      :aria-label="t('maintenance_notice.dismiss')"
      data-maintenance-notice-dismiss
      @click="dismissMaintenanceNotice(notice.key)"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
      </svg>
    </button>
  </div>
</template>

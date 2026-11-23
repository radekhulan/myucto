import { ref } from 'vue'
import { authApi } from '@/api/auth'

/**
 * Ohlášení plánované údržby. Hlášky zadává provozovatel, backend je vydává
 * v `/api/auth/setup-status`, aby je viděla i přihlašovací stránka.
 */
export interface MaintenanceNotice {
  /** Klíč pro zavření. Nese revizi, takže upravená hláška se ukáže znovu. */
  key: string
  severity: 'info' | 'warning' | 'danger'
  starts_at: string
  ends_at: string
  message: { cs: string; en: string }
}

const STORAGE_DISMISSED = 'myinvoice.maintenance_notice.dismissed'
/** Kolik zavřených klíčů si pamatovat. Starší hlášky dávno skončily. */
const MAX_REMEMBERED = 20

/** Poslední obnovený seznam. `null` = zatím neobnoveno, platí setup-status ze startu. */
export const refreshedMaintenanceNotices = ref<MaintenanceNotice[] | null>(null)

export const dismissedMaintenanceNotices = ref<string[]>(readDismissed())

function readDismissed(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_DISMISSED)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : []
  } catch {
    return []
  }
}

export function dismissMaintenanceNotice(key: string): void {
  const next = [...dismissedMaintenanceNotices.value.filter(k => k !== key), key].slice(-MAX_REMEMBERED)
  dismissedMaintenanceNotices.value = next
  try {
    localStorage.setItem(STORAGE_DISMISSED, JSON.stringify(next))
  } catch {
    // Soukromý režim: hláška zůstane zavřená do obnovení stránky.
  }
}

/**
 * Obnoví hlášky bez zásahu do zbytku `setupStatus`. Spadlý dotaz nic nemění,
 * zůstává poslední známý seznam.
 */
export async function refreshMaintenanceNotices(): Promise<void> {
  try {
    const status = await authApi.setupStatus()
    refreshedMaintenanceNotices.value = Array.isArray(status.maintenance_notices) ? status.maintenance_notices : []
  } catch {
    // Ponecháme poslední známý stav.
  }
}

/** Hlášky k zobrazení: nezavřené a ještě neskončené. */
export function visibleMaintenanceNotices(
  notices: MaintenanceNotice[],
  dismissed: string[],
  now: number,
): MaintenanceNotice[] {
  return notices.filter(n => !dismissed.includes(n.key) && Date.parse(n.ends_at) > now)
}

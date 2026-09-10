/**
 * Proč je plánovaná úloha neaktivní. Sdílí kontrola prostředí i stránka
 * Plánované úlohy, ať o téže situaci mluví stejně. Důvody drží backend
 * (`CronJobGate::INACTIVE_*`), tady je jen jejich text.
 */
export interface CronInactiveJob {
  script: string
  reason: string
}

type Translate = (key: string) => string
type Exists = (key: string) => boolean

/** Mapa `skript => důvod` z API; PHP prázdnou mapu posílá jako `[]`. */
export function cronInactiveEntries(map: unknown): CronInactiveJob[] {
  if (!map || typeof map !== 'object' || Array.isArray(map)) return []
  return Object.entries(map as Record<string, unknown>).map(([script, reason]) => ({
    script,
    reason: String(reason),
  }))
}

/** U „nemá co obsluhovat" řekne konkrétně co chybí, jinak obecný důvod. */
export function cronInactiveReason(job: CronInactiveJob, t: Translate, te: Exists): string {
  const specific = `diagnostics.cron_inactive.not_in_use_by_script.${job.script}`
  if (job.reason === 'not_in_use' && te(specific)) return t(specific)
  const key = `diagnostics.cron_inactive.${job.reason}`
  return te(key) ? t(key) : job.reason
}

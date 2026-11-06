/** Zámek dokladu — počítá VÝHRADNĚ backend. FE nikdy neodvozuje ze status/booked_at. */
export type LockReason =
  | 'posted'
  | 'booked'
  | 'period_closed'
  | 'period_closing'
  | 'date_locked'
  | 'accountant_managed'

export interface DocumentLock {
  is_locked: boolean
  reasons: LockReason[]
  booked_at: string | null
  journal_entry_id: number | null
  period_status: string | null
  date_locked: boolean
  accountant_managed: boolean
}

export function draftDeletionLockedForClient(lock: DocumentLock | undefined, hasFinancialLinks = false, linksLoaded = true): boolean {
  return !!lock?.is_locked && (!linksLoaded || hasFinancialLinks || lock.reasons.some(reason =>
    reason !== 'period_closed' && reason !== 'period_closing' && reason !== 'date_locked',
  ))
}

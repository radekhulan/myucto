import { statusBadgeClass } from '@/composables/useFormat'

/** Třída odznaku stavu položky časového rozlišení nad sdílenými stavovými odznaky. */
export function accrualStatusClass(status: string): string {
  const shared: Record<string, string> = {
    draft: 'draft',
    active: 'issued',
    completed: 'paid',
    terminated: 'reminded',
    cancelled: 'cancelled',
  }
  return statusBadgeClass(shared[status] ?? 'draft')
}

/** Třída odznaku stavu řádku plánu rozpouštění. */
export function accrualRowStatusClass(status: string): string {
  const shared: Record<string, string> = {
    planned: 'draft',
    posted: 'paid',
    linked: 'issued',
    cancelled: 'cancelled',
  }
  return statusBadgeClass(shared[status] ?? 'draft')
}

/** Řádek plánu je splatný: plánovaný a s datem rozpuštění nejpozději dnes. */
export function isAccrualRowDue(row: { status: string; due_on: string }, today: string): boolean {
  return row.status === 'planned' && row.due_on <= today
}

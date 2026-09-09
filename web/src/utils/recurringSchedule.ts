/** Vrací klíč překladu chyby; platný termín vrací null. */
export function validateRescheduleDate(date: string, current: string, min: string, max: string | null): string | null {
  const parsed = new Date(`${date}T00:00:00Z`)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== date) {
    return 'recurring.reschedule_invalid_date'
  }
  if (date === current) return 'recurring.reschedule_unchanged'
  if (date < min) return 'recurring.reschedule_too_early'
  if (max && date > max) return 'recurring.reschedule_too_late'
  return null
}

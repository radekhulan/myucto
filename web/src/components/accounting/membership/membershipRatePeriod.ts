import { isIsoDate } from './membershipFormat'
import { addDaysIso, addMonthsIso } from '@/utils/date'

/** Celé kalendářní měsíce; začátek sazby musí být první den měsíce. */
export function membershipRatePeriodEnd(from: string, months: number): string | null {
  if (!isIsoDate(from) || !from.endsWith('-01') || ![1, 3, 6, 12].includes(months)) return null
  const end = addDaysIso(addMonthsIso(from, months), -1)
  return isIsoDate(end) ? end : null
}

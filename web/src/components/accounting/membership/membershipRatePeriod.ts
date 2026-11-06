import { isIsoDate } from './membershipFormat'

/** Období od výslovně zvoleného počátku do dne před měsíčním výročím; členství se tím nemění. */
export function membershipRatePeriodEnd(from: string, months: number): string | null {
  if (!isIsoDate(from) || ![1, 3, 6, 12].includes(months)) return null
  const [year, month, day] = from.split('-').map(Number) as [number, number, number]
  const target = new Date(0)
  target.setUTCFullYear(year, month - 1 + months, 1)
  const targetYear = target.getUTCFullYear(), targetMonth = target.getUTCMonth()
  target.setUTCMonth(targetMonth + 1, 0)
  const anniversaryDay = Math.min(day, target.getUTCDate())
  target.setUTCFullYear(targetYear, targetMonth, anniversaryDay - 1)
  return target.getUTCFullYear() > 9999 ? null : target.toISOString().slice(0, 10)
}

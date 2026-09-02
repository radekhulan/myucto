import type { AccountingPeriod } from '@/api/accounting'

export function findAccountingPeriod(
  periods: readonly AccountingPeriod[],
  periodId: number | '',
): AccountingPeriod | undefined {
  if (periodId === '') return undefined
  return periods.find(period => period.id === Number(periodId))
}

export function calendarYearRange(date: string | null | undefined): { from?: string; to?: string } {
  const year = date?.match(/^(\d{4})-/)?.[1]
  return year ? { from: `${year}-01-01`, to: `${year}-12-31` } : {}
}

export function allAccountingPeriodsRange(
  periods: readonly AccountingPeriod[],
): { from?: string; to?: string } {
  if (periods.length === 0) return {}
  return {
    from: periods.reduce((min, period) => period.starts_on < min ? period.starts_on : min, periods[0]!.starts_on),
    to: periods.reduce((max, period) => period.ends_on > max ? period.ends_on : max, periods[0]!.ends_on),
  }
}

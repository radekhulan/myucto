export function formatPayrollMinutes(value: number): string {
  const sign = value < 0 ? '−' : ''
  const absolute = Math.abs(value)
  return `${sign}${Math.floor(absolute / 60)}:${String(absolute % 60).padStart(2, '0')}`
}

interface WallTime {
  year: number
  month: number
  day: number
  hour: number
  minute: number
}

function wallTimeParts(value: string): WallTime | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)
  if (!match) return null
  const parts = match.slice(1).map(Number)
  const wallTime = {
    year: parts[0],
    month: parts[1],
    day: parts[2],
    hour: parts[3],
    minute: parts[4],
  }
  const check = new Date(Date.UTC(
    wallTime.year,
    wallTime.month - 1,
    wallTime.day,
    wallTime.hour,
    wallTime.minute,
  ))
  return check.getUTCFullYear() === wallTime.year
    && check.getUTCMonth() + 1 === wallTime.month
    && check.getUTCDate() === wallTime.day
    && check.getUTCHours() === wallTime.hour
    && check.getUTCMinutes() === wallTime.minute
    ? wallTime
    : null
}

function renderedWallTime(instant: Date, timezone: string): WallTime | null {
  try {
    const values: Record<string, number> = {}
    for (const part of new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(instant)) {
      if (part.type !== 'literal') values[part.type] = Number(part.value)
    }
    return {
      year: values.year,
      month: values.month,
      day: values.day,
      hour: values.hour,
      minute: values.minute,
    }
  } catch {
    return null
  }
}

function sameWallTime(left: WallTime, right: WallTime): boolean {
  return left.year === right.year
    && left.month === right.month
    && left.day === right.day
    && left.hour === right.hour
    && left.minute === right.minute
}

/** Uložený okamžik zpět do políčka `datetime-local` v zóně zápisu. */
export function payrollIsoToWallTime(value: string, timezone: string): string {
  const instant = new Date(value)
  if (Number.isNaN(instant.getTime())) return ''
  const rendered = renderedWallTime(instant, timezone)
  if (!rendered) return ''
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${rendered.year}-${pad(rendered.month)}-${pad(rendered.day)}T${pad(rendered.hour)}:${pad(rendered.minute)}`
}

export function payrollWallTimeToIso(value: string, timezone: string): string {
  const requested = wallTimeParts(value)
  if (!requested) return ''
  const requestedAsUtc = Date.UTC(
    requested.year,
    requested.month - 1,
    requested.day,
    requested.hour,
    requested.minute,
  )
  let instant = new Date(requestedAsUtc)
  for (let iteration = 0; iteration < 3; iteration += 1) {
    const rendered = renderedWallTime(instant, timezone)
    if (!rendered) return ''
    const renderedAsUtc = Date.UTC(
      rendered.year,
      rendered.month - 1,
      rendered.day,
      rendered.hour,
      rendered.minute,
    )
    instant = new Date(instant.getTime() + requestedAsUtc - renderedAsUtc)
  }
  const rendered = renderedWallTime(instant, timezone)
  if (!rendered || !sameWallTime(requested, rendered)) return ''

  const offsetMinutes = Math.round((requestedAsUtc - instant.getTime()) / 60000)
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const absoluteOffset = Math.abs(offsetMinutes)
  const offset = `${sign}${String(Math.floor(absoluteOffset / 60)).padStart(2, '0')}:${String(absoluteOffset % 60).padStart(2, '0')}`
  return `${value}:00${offset}`
}

interface PayrollFundCoverageSource {
  summary: { fund_minutes: number; actual_minutes: number }
  jmhz_work_summary?: {
    preview: {
      suggestions: { unworked_total_hours: string | null }
      holiday_millihours?: number | null
      holiday_unpaid_millihours?: number | null
    } | null
  } | null
}

function hoursToMinutes(value: string | null | undefined): number {
  const hours = Number(value ?? '')
  return Number.isFinite(hours) && hours > 0 ? Math.round(hours * 60) : 0
}

function millihoursToMinutes(value: number | null | undefined): number {
  return typeof value === 'number' && value > 0 ? Math.round(value * 60 / 1000) : 0
}

/**
 * Pokrývá evidence měsíce fond pracovní doby? Stejné pravidlo jako souhrn
 * měsíce pro JMHZ (`PayrollJmhzWorkMonthSummaryBuilder`): odpracované hodiny
 * (10268) a neodpracované hodiny celkem (10275, tedy nepřítomnosti a svátky
 * v jinak pracovní dny) dávají dohromady sjednaný fond (10260). Svátek,
 * dovolená, nemoc ani překážka proto nejsou „chybějící" hodiny.
 */
export function payrollMonthCoversFund(item: PayrollFundCoverageSource): boolean {
  const preview = item.jmhz_work_summary?.preview ?? null
  const unworked = hoursToMinutes(preview?.suggestions.unworked_total_hours)
    + millihoursToMinutes(preview?.holiday_millihours)
    + millihoursToMinutes(preview?.holiday_unpaid_millihours)

  return item.summary.actual_minutes + unworked >= item.summary.fund_minutes
}
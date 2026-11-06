/**
 * Okamžiky modulu SVJ se zadávají v místním čase Europe/Prague po minutách. API dál přijímá
 * ISO 8601 s posunem; posun (+01:00 / +02:00) se odvodí z data a času, uživatel ho nepíše.
 */
export const PROPERTY_TIME_ZONE = 'Europe/Prague'

const partsFormat = new Intl.DateTimeFormat('en-CA', {
  timeZone: PROPERTY_TIME_ZONE,
  year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
})

export interface LocalInstant { date: string; time: string }

function zoned(epoch: number): { date: string; time: string; seconds: string; wall: number } {
  const map: Record<string, string> = {}
  for (const part of partsFormat.formatToParts(new Date(epoch))) map[part.type] = part.value
  const date = `${map.year}-${map.month}-${map.day}`
  const time = `${map.hour}:${map.minute}`
  return { date, time, seconds: map.second ?? '00', wall: Date.UTC(+map.year!, +map.month! - 1, +map.day!, +map.hour!, +map.minute!, +(map.second ?? 0)) }
}

function offsetMinutes(epoch: number): number {
  const wholeSecond = Math.floor(epoch / 1000) * 1000
  return Math.round((zoned(wholeSecond).wall - wholeSecond) / 60000)
}

function formatOffset(minutes: number): string {
  const sign = minutes < 0 ? '-' : '+'
  const abs = Math.abs(minutes)
  return `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`
}

/** Místní pražské datum a čas (HH:MM) daného okamžiku; neplatný vstup vrací prázdné části. */
export function toLocalInstant(value: string): LocalInstant {
  if (!value) return { date: '', time: '' }
  const epoch = Date.parse(value)
  if (Number.isNaN(epoch)) return { date: '', time: '' }
  const local = zoned(epoch)
  return { date: local.date, time: local.time }
}

/** ISO okamžik s posunem platným v Praze pro zadané místní datum a čas. */
export function fromLocalInstant(date: string, time: string): string {
  const d = date.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  const t = time.match(/^(\d{2}):(\d{2})$/)
  if (!d || !t) return ''
  const wall = Date.UTC(+d[1]!, +d[2]! - 1, +d[3]!, +t[1]!, +t[2]!)
  // Dva průchody: posun se určí pro okamžik, který odpovídá zadanému místnímu času.
  let offset = offsetMinutes(wall - offsetMinutes(wall) * 60000)
  if (zoned(wall - offset * 60000).wall !== wall) offset = offsetMinutes(wall - offset * 60000)
  return `${date}T${time}:00${formatOffset(offset)}`
}

/** Aktuální okamžik zaokrouhlený na minuty v pražském čase. */
export function propertyNow(now: Date = new Date()): string {
  const local = toLocalInstant(now.toISOString())
  return fromLocalInstant(local.date, local.time)
}

/** Čitelné datum a čas v pražském čase (bez sekund); neplatný vstup se zobrazí beze změny. */
export function formatPropertyInstant(value: string | null | undefined, locale = 'cs-CZ'): string {
  if (!value) return ''
  const epoch = Date.parse(value)
  if (Number.isNaN(epoch)) return value
  return new Intl.DateTimeFormat(locale, { timeZone: PROPERTY_TIME_ZONE, day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(epoch)
}

/** Posun vůči UTC pro zobrazení, např. „+02:00“. */
export function localOffset(date: string, time: string): string {
  const value = fromLocalInstant(date, time)
  return value ? value.slice(-6) : ''
}

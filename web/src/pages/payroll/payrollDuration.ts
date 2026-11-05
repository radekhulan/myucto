/** Přesná doba v jednotkách 1/3000 hodiny; minuta = 50, tisícina hodiny = 3. */
export function parsePayrollDuration(raw: string): number | null {
  const value = raw.trim()
  const clock = /^(\d+):([0-5]\d)$/.exec(value)
  if (clock) {
    const units = Number(clock[1]) * 3000 + Number(clock[2]) * 50
    return Number.isSafeInteger(units) ? units : null
  }
  const decimal = /^(\d+)(?:[.,](\d{1,3}))?$/.exec(value)
  if (!decimal) return null
  const units = Number(decimal[1]) * 3000 + Number((decimal[2] ?? '').padEnd(3, '0')) * 3
  return Number.isSafeInteger(units) ? units : null
}

/** Nezaokrouhluje ani součet minut a tisícin; smíšený výsledek ukáže i sekundy. */
export function formatPayrollDuration(units: number | null): string {
  if (units === null) return ''
  if (units % 50 === 0) {
    const minutes = units / 50
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`
  }
  if (units % 3 === 0) {
    const milli = units / 3
    return `${Math.floor(milli / 1000)},${String(milli % 1000).padStart(3, '0').replace(/0+$/, '')}`
  }
  const minutes = Math.floor(units / 50)
  const secondTenths = (units % 50) * 12
  return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}:${String(Math.floor(secondTenths / 10)).padStart(2, '0')}.${secondTenths % 10}`
}

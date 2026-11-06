import { hasSubCentPrice, UNIT_PRICE_DECIMALS } from './unitPrice'

type TimeItem = { unit?: string | null; stock_item_id?: number | null; duration_minutes?: number | null; quantity?: number | string }
type WorkItem = { hours: number | string; rate?: number | string; duration_minutes?: number | null }
type PricedTimeItem = TimeItem & { unit_price_without_vat: number | string }
type SignedTimeItem = { quantity: number | string; duration_minutes?: number | null }

const hourUnits = new Set(['h', 'hod', 'hod.', 'hodina', 'hodiny', 'hour', 'hours', 'hr', 'hrs'])

function minuteProduct(minutes: number, rate: number): bigint | null {
  if (!Number.isSafeInteger(minutes) || !Number.isFinite(rate) || Math.abs(rate) >= 1e21) return null
  return BigInt(minutes) * BigInt(rate.toFixed(6).replace('.', ''))
}

function minuteAmount(minutes: number, rate: number): number {
  const product = minuteProduct(minutes, rate)
  return product === null ? minutes * rate / 60 : Number(product) / 60_000_000
}

function roundedMinuteAmount(minutes: number, rate: number): number {
  const product = minuteProduct(minutes, rate)
  if (product === null) return minutes * rate / 60
  const absolute = product < 0n ? -product : product
  const cents = (absolute + 300_000n) / 600_000n
  return Number(product < 0n ? -cents : cents) / 100
}

export function validateDurationInputs(root: ParentNode = document): boolean {
  for (const input of root.querySelectorAll<HTMLInputElement>('input[data-time-duration]')) {
    if (!input.reportValidity()) return false
  }
  return true
}

export function isTimeItem(item: TimeItem): boolean {
  return !item.stock_item_id && hourUnits.has((item.unit ?? '').trim().toLowerCase())
}

export function syncCreditNoteItemSign(item: SignedTimeItem, negative: boolean): void {
  const quantity = Number(item.quantity)
  if (negative && quantity > 0) item.quantity = -quantity
  if (!negative && quantity < 0) item.quantity = -quantity
  if (item.duration_minutes == null) return
  if (negative && item.duration_minutes > 0) item.duration_minutes = -item.duration_minutes
  if (!negative && item.duration_minutes < 0) item.duration_minutes = -item.duration_minutes
}

export function itemQuantity(item: TimeItem): number {
  return isTimeItem(item) && item.duration_minutes != null ? item.duration_minutes / 60 : Number(item.quantity) || 0
}

export { hasSubCentPrice, UNIT_PRICE_DECIMALS }

/** Přesný řádek: minutová časová položka nebo cena pod haléř (0,004 EUR/ks, sazba 333,333333). */
export function isPreciseUnitPrice(item: PricedTimeItem): boolean {
  return (isTimeItem(item) && item.duration_minutes != null) || hasSubCentPrice(item.unit_price_without_vat)
}

/**
 * Jednotková cena dopočtená z řádkové částky. Haléřová cena zůstává, pokud
 * po vynásobení množstvím vrátí stejnou částku; jinak (levné položky po
 * statisících kusů, dělení třemi) se drží šest míst, aby se částka neztratila.
 */
export function unitPriceForAmount(amount: number, quantity: number): number {
  const exact = amount / quantity
  const cents = Math.round(exact * 100) / 100
  if (Math.round(cents * quantity * 100) === Math.round(amount * 100)) return cents
  return Math.round(exact * 10 ** UNIT_PRICE_DECIMALS) / 10 ** UNIT_PRICE_DECIMALS
}

export function timeItemTotals(item: PricedTimeItem, rate: number, gross: boolean): { base: number; vat: number; with: number } {
  const money = (value: number) => {
    const absolute = Math.abs(value)
    let cents = Math.floor(absolute * 100)
    if ((cents + 1) / 100 === absolute) cents++
    if (absolute >= (cents + 0.5) / 100) cents++
    return cents === 0 ? 0 : Math.sign(value) * cents / 100
  }
  const amount = isTimeItem(item) && item.duration_minutes != null
    ? roundedMinuteAmount(item.duration_minutes, Number(item.unit_price_without_vat) || 0)
    : money(itemAmount(item))
  const vat = money(gross ? amount * rate / (100 + rate) : amount * rate / 100)
  return gross
    ? { base: money(amount - vat), vat, with: amount }
    : { base: amount, vat, with: money(amount + vat) }
}

export function itemAmount(item: PricedTimeItem): number {
  const rate = Number(item.unit_price_without_vat) || 0
  return isTimeItem(item) && item.duration_minutes != null
    ? minuteAmount(item.duration_minutes, rate)
    : (Number(item.quantity) || 0) * rate
}

export function workHours(item: WorkItem): number {
  return item.duration_minutes != null ? item.duration_minutes / 60 : Number(item.hours) || 0
}

export function workAmount(item: WorkItem): number {
  const rate = Number(item.rate) || 0
  return item.duration_minutes != null ? minuteAmount(item.duration_minutes, rate) : (Number(item.hours) || 0) * rate
}

export function workRowTotal(item: WorkItem): number {
  return item.duration_minutes != null
    ? roundedMinuteAmount(item.duration_minutes, Number(item.rate) || 0)
    : workAmount(item)
}

export function formatDuration(minutes: number): string {
  const absolute = Math.abs(minutes)
  return `${minutes < 0 ? '-' : ''}${Math.floor(absolute / 60)}:${String(absolute % 60).padStart(2, '0')}`
}

export function durationTotal(items: { duration_minutes?: number | null }[]): string | null {
  if (items.length === 0 || items.some(item => item.duration_minutes == null)) return null
  return formatDuration(items.reduce((sum, item) => sum + item.duration_minutes!, 0))
}

export function parseDuration(input: string, legacyDecimals = 3): { hours: number; duration_minutes: number | null } | null {
  const text = input.trim().replace(',', '.')
  const time = /^(-?)(\d+):([0-5]\d)$/.exec(text)
  if (time) {
    const minutes = (Number(time[2]) * 60 + Number(time[3])) * (time[1] ? -1 : 1)
    return Number.isSafeInteger(minutes) && Math.abs(minutes) <= 2147483647
      ? { hours: minutes / 60, duration_minutes: minutes } : null
  }
  if (!/^-?\d+(\.\d+)?$/.test(text)) return null
  const hours = Number(text)
  const minutes = hours * 60
  if (!Number.isFinite(hours) || Math.abs(minutes) > 2147483647) return null
  if (Math.abs(minutes - Math.round(minutes)) < 1e-8) {
    return { hours, duration_minutes: Math.round(minutes) }
  }
  return (text.split('.')[1]?.length ?? 0) <= legacyDecimals ? { hours, duration_minutes: null } : null
}

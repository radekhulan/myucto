import { hasSubCentPrice, UNIT_PRICE_DECIMALS } from '@/utils/timeBilling'

/**
 * Ceníková cena a sleva řádku faktury (migrace 2035).
 *
 * `list_unit_price` je cena za jednotku před slevou ve stejném základu jako
 * `unit_price_without_vat`. Editor ji ukládá při nacenění skladové karty a při
 * zadání slevy u řádku. Ruční změna ceny ji nechá být, procento se jen přepočítá. Rozhodné procento počítá
 * server (`LineDiscount::percent`), tady je jen náhled pro řádek editoru.
 */

function toNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

/** Sleva v % (záporná = přirážka) na tři desetinná místa; null bez ceníku, při shodě a u záporné ceny. */
export function lineDiscountPct(listPrice: number | string | null | undefined, unitPrice: number | string | null | undefined): number | null {
  const list = toNumber(listPrice)
  const unit = toNumber(unitPrice)
  if (list === null || unit === null || list <= 0 || unit < 0) return null
  const raw = (list - unit) * 100 / list
  const pct = Math.sign(raw) * Math.round(Math.abs(raw) * 1000 + 1e-7) / 1000
  return pct === 0 ? null : pct
}

/** Ceníková cena z odpovědi nacenění (`/stock/items/quote`). */
export function quotedListPrice(line: { list_unit_price?: string | number | null }): number | null {
  const list = toNumber(line.list_unit_price)
  return list !== null && list > 0 ? list : null
}

/** Ceníková cena karty vybrané bez nacenění: standardní cena bez akce. */
export function legacyListPrice(si: { sale_price_without_vat: string | null }): number | null {
  return quotedListPrice({ list_unit_price: si.sale_price_without_vat })
}

/** Kladné číslo půl nahoru na `decimals` míst. Mezivýsledek se nejdřív ořízne na šest míst, ať 4,35 × 50 % dá 2,18, ne 2,17. */
function roundTo(value: number, decimals: number): number {
  const factor = 10 ** decimals
  return Math.round(Number((value * factor).toFixed(6))) / factor
}

/** Hodnota pole „Sleva %" u řádku: kladná sleva z ceníku na dvě místa, jinak null (bez ceníku, shoda, přirážka). */
export function lineDiscountInputValue(listPrice: number | string | null | undefined, unitPrice: number | string | null | undefined): number | null {
  const pct = lineDiscountPct(listPrice, unitPrice)
  return pct !== null && pct > 0 ? roundTo(pct, 2) : null
}

export function canDiscountUnitPrice(value: number | string | null | undefined): boolean {
  const price = toNumber(value)
  return price !== null && price >= 0
}

/** Zadaná sleva: 0 až 100 (prázdné = 0), desetinná čárka i tečka, volitelně se znakem %, na dvě místa. null = neplatný vstup. */
export function parseLineDiscountInput(raw: string): number | null {
  const text = raw.trim().replace(/\s*%$/, '').replace(',', '.')
  if (text === '') return 0
  if (!/^\d+(\.\d+)?$/.test(text)) return null
  const pct = roundTo(Number(text), 2)
  return pct <= 100 ? pct : null
}

/**
 * Sleva v % zadaná u řádku: cena = ceníková × (1 − sleva / 100), zaokrouhlená půl
 * nahoru na dvě desetinná místa (i u časové položky, ať PDF netiskne šest míst). Bez ceníkové ceny, nebo když je cena ručního
 * řádku nad ceníkovou (pole slevy je prázdné, uživatel cenu opravil), se ceníkovou
 * stane aktuální cena řádku. U skladového řádku je ceníková cena z karty závazná,
 * přirážka hladiny ji nepřepíše. null = nic se nemění (bez kladné ceny, nebo nulová sleva
 * z aktuální ceny).
 */
export function applyLineDiscount(
  row: { stock_item_id?: number | null; list_unit_price?: number | string | null; unit_price_without_vat: number | string | null | undefined },
  pct: number,
): { list_unit_price: number; unit_price_without_vat: number } | null {
  let list = toNumber(row.list_unit_price)
  const current = toNumber(row.unit_price_without_vat)
  if (!canDiscountUnitPrice(current)) return null
  if (list === null || list <= 0 || (row.stock_item_id == null && current !== null && current > list)) {
    if (current === null || current <= 0 || pct === 0) return null
    list = current
  }
  // Ceníková cena pod haléř (0,004/ks) by se na dvou místech vynulovala, drží proto přesnost ceny.
  const decimals = hasSubCentPrice(list) ? UNIT_PRICE_DECIMALS : 2
  return { list_unit_price: list, unit_price_without_vat: roundTo(list * (100 - pct) / 100, decimals) }
}

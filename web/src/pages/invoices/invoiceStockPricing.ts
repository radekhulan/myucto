import type { StockQuotePriceLevel, StockQuotePriceSource } from '@/api/stock'
import { UNIT_PRICE_DECIMALS } from '@/utils/unitPrice'

/**
 * Balení a nacenění skladových řádků faktury (issue #17).
 *
 * Přepočet na základní jednotku zrcadlí backendový StockUnitConverter: základní
 * nebo prázdná jednotka i NEZNÁMÁ jednotka se berou 1:1 (zpětná kompatibilita),
 * kód balení karty (case-insensitive) × numerator / denominator. Tady jde jen
 * o náhled v editoru, závazný přepočet dělá backend při výdeji ze skladu.
 */

export interface PackagingUnitLike {
  unit_code: string
  numerator: number
  denominator: number
}

export interface StockUnitSource {
  unit: string
  units?: PackagingUnitLike[] | null
  default_sale_unit?: string | null
  matched_unit?: string | null
}

/**
 * Karta bez balení a bez individuálních cen jde v editoru PŮVODNÍ cestou (cena
 * z hledání, jednotka karty, číselník jednotek, žádné nacenění) — výchozí chování
 * se nesmí změnit. Nová logika se zapíná jen konfigurací karty.
 */
export function usesStockPricingFeatures(item: { units?: PackagingUnitLike[] | null; has_customer_prices?: boolean | null }): boolean {
  return (item.units?.length ?? 0) > 0 || item.has_customer_prices === true
}

export interface StockQuoteState {
  /** Pořadí posledního požadavku; starší odpověď se zahodí. */
  seq: number
  /** Cena, kterou editor do řádku doplnil sám; null = cenu řídí uživatel. */
  autoPrice: number | null
  source: StockQuotePriceSource | null
  discountPct: string | null
  /** Hladina odběratele, která cenu určila (jen pro badge zdroje ceny). */
  priceLevel?: StockQuotePriceLevel | null
}

/** Má odběratel cenovou hladinu? Bez ní je v hladině „Default" a platí standardní ceny. */
export function clientHasPriceLevel(client: { price_level_id?: number | null } | null | undefined): boolean {
  return client?.price_level_id != null
}

/**
 * Jde řádek novou cestou (nacenění z backendu)? Jen se zapnutým skladem, u řádku
 * s kartou a jen když to zapíná konfigurace: karta s balením / individuálními cenami,
 * nebo vybraný odběratel s cenovou hladinou. Jinak původní cesta beze změny.
 */
export function usesStockQuote(
  row: { stock_item_id?: number | null },
  ctx: { stockEnabled: boolean; cardFeatures: boolean; clientHasLevel: boolean },
): boolean {
  return ctx.stockEnabled && row.stock_item_id != null && (ctx.cardFeatures || ctx.clientHasLevel)
}

export function sameUnit(a: string | null | undefined, b: string | null | undefined): boolean {
  return String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase()
}

export function findPackagingUnit<T extends PackagingUnitLike>(units: T[] | null | undefined, code: string | null | undefined): T | null {
  if (!code || !units) return null
  return units.find(u => sameUnit(u.unit_code, code)) ?? null
}

/** Nabídka jednotek skladového řádku: základní + balení karty (+ uložená jiná jednotka). */
export function stockUnitChoices(baseUnit: string, units: PackagingUnitLike[] | null | undefined, current?: string | null): string[] {
  const out: string[] = [baseUnit]
  for (const u of units ?? []) {
    if (!out.some(code => sameUnit(code, u.unit_code))) out.push(u.unit_code)
  }
  if (current && current.trim() !== '' && !out.some(code => sameUnit(code, current))) out.push(current)
  return out
}

/** Jednotka po výběru karty: shoda EAN balení → výchozí prodejní → základní. */
export function initialStockUnit(item: StockUnitSource): string {
  const matched = findPackagingUnit(item.units, item.matched_unit)
  if (matched) return matched.unit_code
  const preferred = findPackagingUnit(item.units, item.default_sale_unit)
  if (preferred) return preferred.unit_code
  return item.unit
}

/** Poměr balení; null pro základní i neznámou jednotku (ty jsou 1:1). */
export function packagingRatio(
  unit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): { numerator: number; denominator: number } | null {
  if (!unit || sameUnit(unit, baseUnit)) return null
  const found = findPackagingUnit(units, unit)
  if (!found || !(found.numerator > 0) || !(found.denominator > 0)) return null
  return { numerator: found.numerator, denominator: found.denominator }
}

function round(value: number, decimals: number): number {
  const factor = 10 ** decimals
  return Math.round((value + Number.EPSILON * Math.sign(value)) * factor) / factor
}

/** Množství v základní jednotce (3 desetinná místa jako DECIMAL skladu). */
export function toBaseQuantity(
  quantity: number | string,
  unit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): number {
  const qty = Number(quantity) || 0
  const ratio = packagingRatio(unit, baseUnit, units)
  if (!ratio) return round(qty, 3)
  return round(qty * ratio.numerator / ratio.denominator, 3)
}

/**
 * Záložní cena za zvolenou jednotku z ceny za základní jednotku. Použije se jen,
 * když nacenění z backendu selže (jinak by balení dostalo kusovou cenu).
 */
export function unitPriceForUnit(
  basePrice: number,
  unit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): number {
  const ratio = packagingRatio(unit, baseUnit, units)
  if (!ratio) return round(basePrice, UNIT_PRICE_DECIMALS)
  return round(basePrice * ratio.numerator / ratio.denominator, UNIT_PRICE_DECIMALS)
}

/** Množství do nacenění: kladné (dobropis má zápornou položku), prázdné = 1. */
export function quoteQuantity(quantity: number | string | null | undefined): string {
  const qty = Math.abs(Number(quantity) || 0)
  return qty > 0 ? String(round(qty, 3)) : '1'
}

/** Je cena řádku pořád ta, kterou doplnil editor? Ruční přepis se nepřeceňuje. */
export function isPriceStillAuto(state: StockQuoteState | null | undefined, currentPrice: number | string | null | undefined): boolean {
  if (!state || state.autoPrice === null) return false
  return round(Number(currentPrice) || 0, 6) === round(state.autoPrice, 6)
}

export function canRepriceStockPrice(value: number | string | null | undefined): boolean {
  if (value === null || value === undefined || value === '') return false
  const price = Number(value)
  return Number.isFinite(price) && price >= 0
}

export interface StockPricingContext {
  /** Skladová evidence zapnutá; bez ní se editor chová přesně jako dřív. */
  stockEnabled: boolean
  /** Editor dokončil načtení (hydrataci) dokladu; do té doby se nic nepřeceňuje. */
  loaded: boolean
}

interface PricedRow {
  stock_item_id?: number | null
  unit: string
  unit_price_without_vat: number | string
}

/**
 * Řádky ke znovunacenění po změně zákazníka / měny. Jen se zapnutým skladem, po
 * hydrataci a jen řádky karet s cenou doplněnou automaticky v této relaci a od té
 * doby nepřepsanou. Řádky načteného dokladu stav nemají, takže se nikdy nepřeceňují.
 */
export function rowsToRequote<T extends PricedRow>(
  rows: T[],
  stateOf: (row: T) => StockQuoteState | null | undefined,
  ctx: StockPricingContext,
): T[] {
  if (!ctx.stockEnabled || !ctx.loaded) return []
  return rows.filter(row => row.stock_item_id != null && canRepriceStockPrice(row.unit_price_without_vat)
    && isPriceStillAuto(stateOf(row), row.unit_price_without_vat))
}

/**
 * Skladové řádky uloženého dokladu, které po změně cenové hladiny nepřecení automatika
 * (rowsToRequote): cena načtená s dokladem nebo ručně přepsaná. Přecení se jen po
 * výslovném potvrzení uživatele. Nový doklad, vypnutý sklad a hydratace nic nevrací.
 */
export function rowsToConfirmRepricing<T extends PricedRow>(
  rows: T[],
  stateOf: (row: T) => StockQuoteState | null | undefined,
  ctx: StockPricingContext & { storedDocument: boolean },
): T[] {
  if (!ctx.stockEnabled || !ctx.loaded || !ctx.storedDocument) return []
  return rows.filter(row => row.stock_item_id != null && canRepriceStockPrice(row.unit_price_without_vat)
    && !isPriceStillAuto(stateOf(row), row.unit_price_without_vat))
}

export interface ContextRepricing<T> {
  /** Řádky k nacenění (karta s funkcemi nebo odběratel s hladinou). */
  quote: T[]
  /** Řádky, kterým se vrátí původní cena karty (karta bez funkcí, odběratel bez hladiny). */
  legacy: T[]
}

/**
 * Po změně odběratele / měny rozdělí řádky s pořád automatickou cenou (rowsToRequote,
 * tedy se všemi jeho pojistkami) na nacenění a návrat původní ceny. Ruční ceny,
 * řádky bez karty, načtený doklad i vypnutý sklad do žádné skupiny nepatří.
 */
export function planContextRepricing<T extends PricedRow>(
  rows: T[],
  stateOf: (row: T) => StockQuoteState | null | undefined,
  ctx: StockPricingContext & { clientHasLevel: boolean },
  cardFeaturesOf: (row: T) => boolean,
): ContextRepricing<T> {
  const plan: ContextRepricing<T> = { quote: [], legacy: [] }
  for (const row of rowsToRequote(rows, stateOf, ctx)) {
    if (ctx.clientHasLevel || cardFeaturesOf(row)) plan.quote.push(row)
    else plan.legacy.push(row)
  }
  return plan
}

/**
 * Původní cena karty (effective_price ?? sale_price_without_vat), kterou řádek dostal
 * dosavadní cestou. null = karta bez ceny nebo neznámá karta; řádek se pak nemění.
 */
export function legacyRestorePrice(si: StockSelectionSource | null | undefined): number | null {
  if (!si) return null
  const price = legacyStockSelection(si).price
  return price !== null && Number.isFinite(price) ? price : null
}

/**
 * Nabídka jednotek řádku. null = beze změny, tedy globální číselník jednotek
 * (sklad vypnutý nebo řádek bez karty). Jinak základní jednotka + balení karty
 * + aktuální jednotka řádku, pokud je jiná (zůstane zachovaná).
 */
export function rowUnitOptions(
  row: { stock_item_id?: number | null; unit: string },
  ctx: { stockEnabled: boolean; features: boolean },
  baseUnit: string | null | undefined,
  units: PackagingUnitLike[] | null | undefined,
  globalUnits: readonly string[] = [],
): string[] | null {
  if (!ctx.stockEnabled || !ctx.features || row.stock_item_id == null) return null
  const out = stockUnitChoices(baseUnit || row.unit, units, row.unit)
  // Zbytek globálního číselníku, ať z nabídky nezmizí nic, co šlo vybrat dřív.
  for (const code of globalUnits) {
    if (code && !out.some(c => sameUnit(c, code))) out.push(code)
  }
  return out
}

function ratioOf(unit: string | null | undefined, baseUnit: string, units: PackagingUnitLike[] | null | undefined) {
  return packagingRatio(unit, baseUnit, units) ?? { numerator: 1, denominator: 1 }
}

/**
 * Přecenit po změně jednotky? Jen když je cena pořád automatická, nebo když se
 * mění poměr (balení). Ručně zadanou cenu přepnutí mezi jednotkami 1:1 nepřepíše.
 */
export function shouldRequoteOnUnitChange(
  state: StockQuoteState | null | undefined,
  currentPrice: number | string | null | undefined,
  oldUnit: string | null | undefined,
  newUnit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): boolean {
  if (!canRepriceStockPrice(currentPrice) || sameUnit(oldUnit, newUnit)) return false
  if (isPriceStillAuto(state, currentPrice)) return true
  const a = ratioOf(oldUnit, baseUnit, units)
  const b = ratioOf(newUnit, baseUnit, units)
  return a.numerator * b.denominator !== b.numerator * a.denominator
}

/** Jednotka v textu dostupnosti: základní jen u balení se známým poměrem, jinak jednotka řádku. */
export function availabilityUnit(unit: string, baseUnit: string, units: PackagingUnitLike[] | null | undefined): string {
  return packagingRatio(unit, baseUnit, units) ? baseUnit : unit
}

/** Cena z nacenění; null = backend pro danou měnu cenu nemá a řádek si nechá svou. */
/**
 * Cena řádku přepočtená mezi jednotkami (z → základní → do) podle balení karty.
 * Použije se, když nacenění nevrátí cenu (karta nemá cenu v měně dokladu), ale
 * jednotka se mění — kusová cena by jinak zůstala u palety.
 */
export function convertUnitPrice(
  price: number,
  fromUnit: string | null | undefined,
  toUnit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): number {
  const from = packagingRatio(fromUnit, baseUnit, units)
  const perBase = from ? price * from.denominator / from.numerator : price
  return unitPriceForUnit(perBase, toUnit, baseUnit, units)
}

/**
 * Cena řádku, když nacenění cenu nevrátí, ale jednotka se mění. Přepočte se jen
 * cena, kterou doplnil editor; cizí cena (ruční přepis, hodinová sazba klienta
 * u nového řádku) zůstane beze změny → null.
 */
export function priceForMissingQuote(
  state: StockQuoteState | null | undefined,
  currentPrice: number,
  fromUnit: string | null | undefined,
  toUnit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): number | null {
  if (!isPriceStillAuto(state, currentPrice)) return null
  const converted = convertUnitPrice(currentPrice, fromUnit, toUnit, baseUnit, units)
  return converted === currentPrice ? null : converted
}

export function quotedUnitPrice(line: { unit_price?: string | number | null }): number | null {
  if (line.unit_price === null || line.unit_price === undefined || line.unit_price === '') return null
  const price = Number(line.unit_price)
  return Number.isFinite(price) ? price : null
}

export interface StockSelectionSource {
  unit: string
  effective_price?: string | null
  sale_price_without_vat: string | null
  promo_price?: string | null
}

/**
 * Hodnoty řádku po výběru karty podle dosavadního chování (základní jednotka,
 * effective_price ?? sale_price_without_vat, toast o akci). Zapisují se hned
 * a zůstanou, když nacenění selže.
 */
export function legacyStockSelection(si: StockSelectionSource): { unit: string; price: number | null; promoToast: boolean } {
  const raw = si.effective_price ?? si.sale_price_without_vat
  return { unit: si.unit, price: raw == null ? null : Number(raw), promoToast: si.promo_price != null }
}

export type QuoteMode = 'select' | 'unit_change' | 'context'

/**
 * Co udělat, když nacenění selže: po výběru karty zůstává dosavadní chování
 * (včetně toastu o akci), po změně jednotky či zákazníka se řádek nemění.
 */
export function quoteFailureFallback(mode: QuoteMode, si: StockSelectionSource | null | undefined): { unit: string; price: number | null; promoToast: boolean } | null {
  if (mode !== 'select' || !si) return null
  return legacyStockSelection(si)
}

export interface PendingQuote {
  seq: number
  stockItemId: number
  /** Jednotka, se kterou se nacenění poslalo. */
  requestUnit: string
  /** Jednotka a cena řádku v okamžiku odeslání. Změna mezitím odpověď zneplatní. */
  unitAtRequest: string
  priceAtRequest: number
}

/** Smí se odpověď nacenění zapsat do řádku? Ne, když je starší nebo řádek mezitím někdo změnil. */
export function canApplyQuote(pending: PendingQuote, row: PricedRow, state: StockQuoteState | null | undefined): boolean {
  if (!state || state.seq !== pending.seq) return false
  if (row.stock_item_id !== pending.stockItemId) return false
  if (!sameUnit(row.unit, pending.unitAtRequest)) return false
  return Number(row.unit_price_without_vat) === pending.priceAtRequest
}

/**
 * Jednotka řádku po úspěšném nacenění. Mění se jen po výběru karty (balení
 * dle EAN / výchozí prodejní jednotky); po změně jednotky nebo zákazníka zůstává.
 */
export function unitAfterQuote(mode: QuoteMode, requestUnit: string, line: { unit: string | null; base_unit: string }): string | null {
  if (mode !== 'select') return null
  if (line.unit && sameUnit(line.unit, requestUnit)) return requestUnit
  return line.base_unit || requestUnit
}

/**
 * Množství pro kontrolu dostupnosti: v základní jednotce jen u balení se známým
 * poměrem, jinak přesně jako dřív (absolutní hodnota množství řádku).
 */
export function availabilityQuantity(
  quantity: number | string,
  unit: string | null | undefined,
  baseUnit: string,
  units: PackagingUnitLike[] | null | undefined,
): number {
  if (!packagingRatio(unit, baseUnit, units)) return Math.abs(Number(quantity) || 0)
  return Math.abs(toBaseQuantity(quantity, unit, baseUnit, units))
}

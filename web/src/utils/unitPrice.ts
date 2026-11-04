/**
 * Přesnost jednotkové ceny (zrcadlí UnitPricePrecision na serveru). Cena se ukládá
 * na šest desetinných míst, ukazuje se na dvě a víc jen tehdy, když je cena má.
 */
export const UNIT_PRICE_DECIMALS = 6

export function hasSubCentPrice(price: number | string | null | undefined): boolean {
  const value = Number(price)
  return Number.isFinite(value) && Math.abs(value - Math.round(value * 100) / 100) > 1e-7
}

/** Zaokrouhlí jednotkovou cenu na šest míst (half-up), jako server. */
export function roundUnitPrice(price: number): number {
  const scale = 10 ** UNIT_PRICE_DECIMALS
  return Math.sign(price) * Math.round(Math.abs(price) * scale + 1e-6) / scale
}

/**
 * Cena vypočtená procentem nebo kurzem (zrcadlí UnitPricePrecision::roundDerived):
 * na haléře, pokud tím neztratí víc než 1 % hodnoty, jinak na šest míst.
 */
export function roundDerivedPrice(price: number): number {
  const exact = roundUnitPrice(price)
  const cents = Math.sign(price) * Math.round(Math.abs(price) * 100 + 1e-6) / 100
  return Math.abs(exact - cents) * 100 > Math.abs(exact) ? exact : cents
}

/** Počet míst k zobrazení: 2, u ceny pod haléř tolik, kolik jich má (nejvýš 6). */
export function unitPriceDecimals(price: number | string | null | undefined): number {
  const value = Number(price)
  if (!Number.isFinite(value) || !hasSubCentPrice(value)) return 2
  return Math.max(2, (Math.abs(value).toFixed(UNIT_PRICE_DECIMALS).replace(/0+$/, '').split('.')[1] ?? '').length)
}

/**
 * Hodnota ceny do formulářového pole: „12.500000" → „12.50", „0.010800" → „0.0108".
 * Prázdná a nečíselná hodnota projde beze změny.
 */
export function unitPriceText<T>(price: T): T | string {
  if (price === null || price === undefined || price === '') return price
  const value = Number(price)
  if (!Number.isFinite(value)) return price
  return value.toFixed(unitPriceDecimals(value))
}

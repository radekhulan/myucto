/**
 * Číslo z importovaných dat bez ohledu na zvyklost zdroje („128,5", „128.5",
 * „1 234,50", „1.234,50", „1,234.50", „71 875,00 Kč"). Protějšek PHP
 * `MyInvoice\Support\LocaleNumber`: obsahuje-li hodnota čárku i tečku, desetinný
 * je ten poslední; jediný oddělovač je desetinný, opakovaný je oddělovač tisíců.
 */
export function parseLocaleNumber(value: string | number | null | undefined): number | null {
  if (value == null) return null
  if (typeof value === 'number') return Number.isFinite(value) ? value : null
  let s = value.replace(/[\s    '’]/g, '').replace(/(kč|czk|eur|€|usd|\$|km|ks)$/i, '')
  if (s === '') return null
  let negative = false
  const paren = /^\((.*)\)$/.exec(s)
  if (paren) { negative = true; s = paren[1] }
  if (/^[-−]/.test(s)) { negative = !negative; s = s.slice(1) } else if (s.startsWith('+')) s = s.slice(1)

  const lastComma = s.lastIndexOf(',')
  const lastDot = s.lastIndexOf('.')
  if (lastComma >= 0 && lastDot >= 0) {
    s = lastComma > lastDot ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '')
  } else if (lastComma >= 0) {
    s = (s.match(/,/g)?.length ?? 0) > 1 ? s.replace(/,/g, '') : s.replace(',', '.')
  } else if ((s.match(/\./g)?.length ?? 0) > 1) {
    s = s.replace(/\./g, '')
  }
  if (!/^(\d+(\.\d*)?|\.\d+)$/.test(s)) return null
  const n = Number(s)
  return negative && n !== 0 ? -n : n
}

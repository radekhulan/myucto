/**
 * Zóna, ve které se vede účetnictví.
 *
 * Není to zóna prohlížeče a nemá jí být: účetní může sedět kdekoli, ale doklady
 * se vystavují do českého kalendáře. Účetní na dovolené v USA má v 19:00 místního
 * času v Praze už 01:00 následujícího dne — kdyby se datum bralo z prohlížeče,
 * dostal by doklad DUZP o den zpět proti tomu, co vidí kolegové v kanceláři
 * i co si myslí backend, a poslední den v měsíci by spadl do jiného přiznání.
 *
 * Hodnota odpovídá `app.timezone` na backendu. Až bude instalace mimo ČR reálná,
 * patří sem hodnota z konfigurace — do té doby by ji jen nikdo neudržoval.
 */
export const APP_TIME_ZONE = 'Europe/Prague'

const appDateParts = new Intl.DateTimeFormat('en-CA', {
  timeZone: APP_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

/**
 * Kalendářní datum daného okamžiku v účetní zóně — „dnešek", jak ho vidí firma.
 *
 * Tohle patří všude, kde se předvyplňuje datum dokladu: DUZP, datum vystavení,
 * datum úhrady, datum pořízení majetku. `toISOString().slice(0, 10)` sem NEPATŘÍ,
 * ten renderuje v UTC a v Praze vrací mezi půlnocí a druhou hodinou včerejšek.
 */
export function appIsoDate(date: Date = new Date()): string {
  const parts = appDateParts.formatToParts(date)
  const value = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find(part => part.type === type)?.value ?? ''

  return `${value('year')}-${value('month')}-${value('day')}`
}

/** Rok v účetní zóně — ať se rozsahy sestav neberou z jiného kalendáře než `as_of`. */
export function appYear(date: Date = new Date()): string {
  return appIsoDate(date).slice(0, 4)
}

/** Celé kalendářní dny po splatnosti v účetní zóně, nezávisle na změnách letního času. */
export function overdueDays(dueDate: string, now: Date = new Date()): number {
  if (!parseIsoDate(dueDate)) return 0
  const days = (Date.parse(appIsoDate(now)) - Date.parse(dueDate)) / 86_400_000
  return Number.isFinite(days) ? Math.max(0, days) : 0
}

/**
 * `YYYY-MM-DD` složené z LOKÁLNÍCH složek `Date`.
 *
 * Slouží jen kalendářní aritmetice níž, kde se `Date` používá jako kalendář
 * (přičti den, najdi poslední den měsíce) a žádný okamžik nereprezentuje —
 * převod do jiné zóny by tam datum posunul, ne opravil.
 */
function isoFromLocalParts(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/**
 * Rozebere `YYYY-MM-DD` na čísla; `null` u čehokoli jiného.
 *
 * Prázdný nebo rozepsaný vstup musí vrátit vstup beze změny, ne `'NaN-NaN-NaN'`.
 * Formulář fakturace počítá splatnost při každé změně klienta — s vymazaným datem
 * vystavení by se nesmysl uložil do `due_date` a odešel na API.
 */
function parseIsoDate(value: string): [number, number, number] | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null

  const parsed: [number, number, number] = [Number(match[1]), Number(match[2]), Number(match[3])]
  return parsed.every(Number.isFinite) ? parsed : null
}

/**
 * `YYYY-MM-DD` + N dnů, čistě kalendářně.
 *
 * Nepočítá se přes `toISOString()`: přechod na letní/zimní čas uvnitř intervalu
 * posune výsledek o hodinu a datum pak přeteče přes půlnoc na sousední den.
 */
export function addDaysIso(date: string, days: number): string {
  const parts = parseIsoDate(date)
  if (!parts) return date

  const [year, month, day] = parts
  return isoFromLocalParts(new Date(year, month - 1, day + days))
}

/**
 * `YYYY-MM-DD` + N měsíců se zachováním dne; když cílový měsíc takový den nemá
 * (31. 1. + 1 měsíc → „31. 2."), vrátí jeho poslední den (28./29. 2.).
 */
export function addMonthsIso(date: string, months: number): string {
  const parts = parseIsoDate(date)
  if (!parts) return date

  const [year, month, day] = parts
  const targetMonth = month - 1 + months
  const lastDay = new Date(year, targetMonth + 1, 0).getDate()
  return isoFromLocalParts(new Date(year, targetMonth, Math.min(day, lastDay)))
}

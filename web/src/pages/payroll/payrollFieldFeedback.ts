/**
 * Chyba a varování vázané na konkrétní pole formuláře podmínek.
 *
 * Server u validační chyby pole jmenuje v `error.details.field` (klíč těla
 * podmínek, např. `work_place`, `activity_code`) a u chyby, na kterou jde
 * nabídnout další krok, posílá i `error.details.code`. Starší odpověď bez
 * `details` nic nerozbije: chyba se pak ukáže jen v souhrnném pruhu, jako dřív.
 */

interface ErrorDetails {
  field?: unknown
  code?: unknown
  [key: string]: unknown
}

function errorBody(error: unknown): { details?: ErrorDetails; field?: unknown } | null {
  const body = (error as { response?: { data?: { error?: unknown } } })?.response?.data?.error
  return typeof body === 'object' && body !== null ? body as { details?: ErrorDetails; field?: unknown } : null
}

/** Podrobnosti chyby (`error.details`), nebo prázdný objekt. */
export function apiErrorDetails(error: unknown): ErrorDetails {
  const details = errorBody(error)?.details
  return typeof details === 'object' && details !== null ? details : {}
}

/** Klíč pole, kvůli kterému server uložení odmítl; `null`, když ho neuvedl. */
export function apiErrorField(error: unknown): string | null {
  const field = apiErrorDetails(error).field ?? errorBody(error)?.field
  return typeof field === 'string' && field !== '' ? field : null
}

/** Strojový kód z `error.details.code` (např. `recurring_base_wage_active`). */
export function apiErrorDetailCode(error: unknown): string | null {
  const code = apiErrorDetails(error).code
  return typeof code === 'string' && code !== '' ? code : null
}

/**
 * Stejná hláška jen jednou. Server ji hlásí po úsecích pracovní doby, takže
 * „doložte příplatky" přišla tolikrát, kolik má měsíc úseků — a pro člověka
 * je to pořád jedna věc, kterou má udělat.
 */
export function uniqueBy<T>(items: readonly T[], key: (item: T) => string): T[] {
  const seen = new Set<string>()
  return items.filter((item) => {
    const value = key(item)
    if (seen.has(value)) return false
    seen.add(value)
    return true
  })
}

/** Den před `isoDate` (YYYY-MM-DD), počítaný v UTC, aby nehrálo pásmo. */
export function previousCalendarDay(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day! - 1))
  return date.toISOString().slice(0, 10)
}

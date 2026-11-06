/**
 * Stálé technické označení záznamu SVJ (`[A-Za-z0-9_.-]{1,80}`) odvozené z textu, který uživatel
 * skutečně píše (název domu, číslo jednotky). Kolize s existujícím kódem téhož číselníku se řeší
 * číselnou příponou, porovnání je bez ohledu na velikost písmen stejně jako na serveru.
 */
export function propertyCode(text: string, taken: Iterable<string> = [], fallback = 'zaznam'): string {
  const base = (text.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9_.-]+/g, '-').replace(/^[-.]+|[-.]+$/g, '').slice(0, 70) || fallback)
  const used = new Set([...taken].map(code => code.toLowerCase()))
  if (!used.has(base.toLowerCase())) return base
  for (let n = 2; ; n++) {
    const candidate = `${base}-${n}`
    if (!used.has(candidate.toLowerCase())) return candidate
  }
}

/** 1. ledna aktuálního roku v 00:00 pražského času, výchozí začátek evidence SVJ. */
export function propertyYearStart(now: Date = new Date()): string {
  const year = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Prague', year: 'numeric' }).format(now)
  return `${year}-01-01T00:00:00+01:00`
}

/** Pozdější ze dvou okamžiků (ISO s posunem); prázdné hodnoty se přeskočí. */
export function laterInstant(...values: Array<string | null | undefined>): string {
  let best = ''
  for (const value of values) if (value && (!best || Date.parse(value) > Date.parse(best))) best = value
  return best
}

/**
 * Převody mezi ISO datem (`YYYY-MM-DD`, tvar v datech i v API) a textem,
 * jak ho píše a čte uživatel v jazyce aplikace.
 *
 * Why: nativní `<input type="date">` formátuje podle jazyka PROHLÍŽEČE, ne
 * aplikace. Uživatel s anglickým systémem a českým rozhraním tak vidí vedle
 * sebe „01. 09. 2026" (vypsané) a „09/01/2026" (editovatelné) a u prvních
 * dvanácti dnů měsíce nepozná prohozený měsíc. Tady se formát odvozuje z Intl
 * pro jazyk aplikace — stejně jako `formatDate()` v useFormat — a parser bere
 * stejné pořadí složek, takže zápis i výpis mluví jedním jazykem.
 *
 * Čistě funkční modul bez závislosti na i18n instanci, aby se dal testovat
 * bez mountování komponent.
 */

export type DatePart = 'day' | 'month' | 'year'

export interface DateInputLocale {
  /** BCP-47 tag pro Intl (`cs-CZ`, `en-US`). */
  tag: string
  /** Pořadí složek, jak ho jazyk píše (cs: den-měsíc-rok, en-US: měsíc-den-rok). */
  order: DatePart[]
  /** Oddělovač složek bez okolních mezer (`.` nebo `/`). */
  separator: string
}

const ISO_RE = /^(\d{4})-(\d{2})-(\d{2})$/

/** Stejné mapování jazyka aplikace na Intl tag jako v useFormat.ts. */
export function localeTag(appLocale: string): string {
  return appLocale === 'en' ? 'en-US' : 'cs-CZ'
}

const localeCache = new Map<string, DateInputLocale>()

/** Pořadí složek a oddělovač jazyka, odvozené z Intl (ne ručně psaná tabulka). */
export function dateInputLocale(appLocale: string): DateInputLocale {
  const tag = localeTag(appLocale)
  const cached = localeCache.get(tag)
  if (cached) return cached

  const parts = new Intl.DateTimeFormat(tag, { day: '2-digit', month: '2-digit', year: 'numeric' })
    .formatToParts(new Date(2001, 11, 31))
  const order = parts
    .map(part => part.type)
    .filter((type): type is DatePart => type === 'day' || type === 'month' || type === 'year')
  const literal = parts.find(part => part.type === 'literal')?.value.trim() ?? '.'
  const resolved: DateInputLocale = {
    tag,
    order: order.length === 3 ? order : ['day', 'month', 'year'],
    separator: literal === '' ? '.' : literal,
  }
  localeCache.set(tag, resolved)
  return resolved
}

/** Rozloží platné ISO datum na čísla; `null` pro cokoli jiného (včetně 31. 2.). */
export function isoParts(iso: string): { year: number; month: number; day: number } | null {
  const match = ISO_RE.exec(iso)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  return isRealDate(year, month, day) ? { year, month, day } : null
}

function isRealDate(year: number, month: number, day: number): boolean {
  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return false
  if (year < 1000 || year > 9999 || month < 1 || month > 12 || day < 1) return false
  return day <= new Date(year, month, 0).getDate()
}

function toIso(year: number, month: number, day: number): string {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * ISO → text v jazyce aplikace (`2026-09-01` → „01. 09. 2026" / „09/01/2026").
 * Neplatný nebo prázdný vstup vrací prázdný řetězec — pole se nemá tvářit vyplněné.
 */
export function formatIsoForInput(iso: string | null | undefined, appLocale: string): string {
  if (!iso) return ''
  const parts = isoParts(iso)
  if (!parts) return ''
  return new Intl.DateTimeFormat(localeTag(appLocale), { day: '2-digit', month: '2-digit', year: 'numeric' })
    .format(new Date(parts.year, parts.month - 1, parts.day))
}

/**
 * Text od uživatele → ISO, nebo `null`, když to (ještě) není platné datum.
 *
 * Přijímá:
 *  - složky v pořadí jazyka oddělené `.`, `/`, `-` nebo mezerou, s libovolnými
 *    mezerami okolo (`1.9.2026`, `01. 09. 2026`, `9/1/2026`),
 *  - zápis bez roku (`1.10.`, `1.10`) jako aktuální rok,
 *  - dvouciferný rok jako 20xx (`1.9.26`),
 *  - souvislé číslice, rok vždy na konci: 4 (`0110`, bez roku), 6 (`011026`)
 *    nebo 8 (`01102026`), den a měsíc v pořadí jazyka,
 *  - ISO `YYYY-MM-DD` v každém jazyce (co přijde ze schránky nebo z API).
 * Zkrácené zápisy (bez roku, dvouciferný rok) jen s `allowShortYear` (default);
 * během psaní je volající vypne, protože „1.9.20" nebo „0110" jsou jen mezistavy
 * cesty k „1.9.2026" a jako rok 2020 / aktuální rok by spustily přepočty nad
 * špatným datem. Odmítá neexistující dny (`31. 2.`) — vrátit posunuté datum
 * by bylo horší než nic.
 */
export function parseInputDate(
  text: string,
  appLocale: string,
  { allowShortYear = true, today = new Date() }: { allowShortYear?: boolean; today?: Date } = {},
): string | null {
  const raw = text.trim()
  if (raw === '') return null

  const iso = ISO_RE.exec(raw)
  if (iso) return isoParts(raw) ? raw : null

  const { order } = dateInputLocale(appLocale)
  const dayMonthOrder = order.filter(part => part !== 'year')
  const yearLast = order[order.length - 1] === 'year'
  let tokens: string[]
  let yearToken: string | null
  if (/^\d+$/.test(raw)) {
    if (![4, 6, 8].includes(raw.length) || !yearLast) return null
    tokens = [raw.slice(0, 2), raw.slice(2, 4)]
    yearToken = raw.length > 4 ? raw.slice(4) : null
  } else {
    const all = raw.split(/\s*[./\-\s]\s*/).filter(token => token !== '')
    if (all.some(token => !/^\d{1,4}$/.test(token))) return null
    if (all.length === 3) {
      const yearIndex = order.indexOf('year')
      yearToken = all[yearIndex]
      tokens = all.filter((_, index) => index !== yearIndex)
    } else if (all.length === 2 && yearLast) {
      tokens = all
      yearToken = null
    } else {
      return null
    }
  }
  if (tokens.some(token => token.length > 2)) return null

  const values: Record<DatePart, number> = { day: 0, month: 0, year: today.getFullYear() }
  dayMonthOrder.forEach((part, index) => {
    values[part] = Number(tokens[index])
  })
  if (yearToken === null) {
    if (!allowShortYear) return null
  } else if (yearToken.length === 2) {
    if (!allowShortYear) return null
    values.year = 2000 + Number(yearToken)
  } else if (yearToken.length === 4) {
    values.year = Number(yearToken)
  } else {
    return null
  }

  return isRealDate(values.year, values.month, values.day)
    ? toIso(values.year, values.month, values.day)
    : null
}

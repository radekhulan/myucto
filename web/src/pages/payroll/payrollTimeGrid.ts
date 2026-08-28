import type {
  PayrollTimeBatchCell,
  PayrollTimeCategory,
  PayrollTimeEntry,
  PayrollTimeOverviewItem,
} from '@/api/payroll'
import { payrollWallTimeToIso } from '@/pages/payroll/payrollTime'

/**
 * Měsíční mřížka docházky: zaměstnanci × dny.
 *
 * Why: docházka se dosud zadávala po JEDNOM intervalu v modálním editoru, který
 * se po uložení zavíral a datum si vždy přepsal na první den měsíce. Dvanáct
 * dnů jednoho člověka = dvacet čtyři přepsaných dat a dvanáct uložení; dvacet
 * lidí za měsíc už nikdo neklikal a jediná průchodná cesta byl CSV import,
 * o kterém stránka mlčela.
 *
 * Logika je schválně v samostatném modulu, ne v `.vue`: převod „8:30" na minuty,
 * mapování zápisů na dny a pohyb po mřížce klávesnicí jsou tři věci, které se
 * musí dát otestovat bez mountování stránky s dvaceti mocky.
 *
 * ─── Kategorie ──────────────────────────────────────────────────────────────
 * Docházka nese šest kategorií, ale jen `regular` a `overtime` jsou hodiny;
 * `night`, `weekend`, `holiday` a `difficult_environment` jsou PŘÍZNAKY nad
 * týmiž hodinami (backend počítá odpracovaný čas jako `regular + overtime`,
 * viz `PayrollTimeService::overview`). Mřížka proto edituje vždy jednu vrstvu
 * a odpracovanou dobu v souhrnném sloupci skládá jen ze dvou hodinových
 * kategorií — jinak by měsíc s nočními směnami tvrdil dvojnásobek.
 */

/** Kategorie, které se sčítají do odpracované doby. */
export const PAYROLL_WORKED_CATEGORIES: readonly PayrollTimeCategory[] = ['regular', 'overtime']

/** Kategorie, které jsou jen příznakem nad týmiž hodinami. */
export const PAYROLL_FLAG_CATEGORIES: readonly PayrollTimeCategory[] = [
  'night',
  'weekend',
  'holiday',
  'difficult_environment',
]

export function isWorkedCategory(category: PayrollTimeCategory): boolean {
  return PAYROLL_WORKED_CATEGORIES.includes(category)
}

export interface PayrollGridDay {
  /** `YYYY-MM-DD` */
  date: string
  /** 1 až 31 */
  day: number
  /** 1 = pondělí … 7 = neděle */
  weekday: number
  weekend: boolean
}

/**
 * Dny měsíce. Počítá se z `YYYY-MM` přes UTC, aby se délka února ani den
 * v týdnu nelišily podle časového pásma prohlížeče.
 */
export function payrollMonthDays(period: string): PayrollGridDay[] {
  const match = /^(\d{4})-(\d{2})$/.exec(period)
  if (!match) return []
  const year = Number(match[1])
  const month = Number(match[2])
  if (month < 1 || month > 12) return []
  const days: PayrollGridDay[] = []
  const total = new Date(Date.UTC(year, month, 0)).getUTCDate()
  for (let day = 1; day <= total; day += 1) {
    const date = new Date(Date.UTC(year, month - 1, day))
    const weekday = date.getUTCDay() === 0 ? 7 : date.getUTCDay()
    days.push({
      date: `${match[1]}-${match[2]}-${String(day).padStart(2, '0')}`,
      day,
      weekday,
      weekend: weekday >= 6,
    })
  }
  return days
}

export type PayrollGridDayKind = 'workday' | 'non_working' | 'holiday'

export interface PayrollGridDayPlan {
  kind: PayrollGridDayKind
  plannedMinutes: number
  holidayName: string | null
}

/**
 * Druh dne a plánované minuty podle kalendáře vztahu.
 *
 * Kalendář má přednost, protože zná i směnný provoz a svátky
 * (`CzechHolidayCalendar` je promítnutý do `calendar.days`). Bez kalendáře
 * zbývá jediný poctivý odhad — pondělí až pátek — a ten se v mřížce označí
 * jako odhad, ať uživatel neplní svátky jen proto, že o nich mřížka neví.
 */
export function payrollDayPlans(
  item: Pick<PayrollTimeOverviewItem, 'calendar'>,
  days: PayrollGridDay[],
  fallbackMinutes: number,
): Map<string, PayrollGridDayPlan> {
  const plans = new Map<string, PayrollGridDayPlan>()
  const calendarDays = item.calendar?.days ?? []
  const byDate = new Map(calendarDays.map(day => [day.date, day]))
  for (const day of days) {
    const known = byDate.get(day.date)
    if (known) {
      plans.set(day.date, {
        kind: known.day_kind,
        plannedMinutes: known.planned_minutes,
        holidayName: known.holiday_name,
      })
      continue
    }
    plans.set(day.date, {
      kind: day.weekend ? 'non_working' : 'workday',
      plannedMinutes: day.weekend ? 0 : fallbackMinutes,
      holidayName: null,
    })
  }
  return plans
}

/**
 * Hodiny z políčka na minuty.
 *
 * Účetní píše „8", „8:30" i „7,5" — všechny tři tvary se berou, protože
 * odmítnout desetinnou čárku na české klávesnici je jen šikana. `null` znamená
 * prázdno, `false` nečitelný zápis; obojí musí volající rozlišit.
 */
export function parsePayrollGridHours(raw: string): number | null | false {
  const value = raw.trim()
  if (value === '') return null
  const colon = /^(\d{1,2}):([0-5]\d)$/.exec(value)
  if (colon) {
    const minutes = Number(colon[1]) * 60 + Number(colon[2])
    return minutes > 24 * 60 ? false : minutes
  }
  const decimal = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(value)
  if (!decimal) return false
  const hours = Number(`${decimal[1]}.${decimal[2] ?? '0'}`)
  const minutes = Math.round(hours * 60)
  return minutes > 24 * 60 ? false : minutes
}

/** Minuty zpátky do políčka: celé hodiny bez dvojtečky, zbytek s ní. */
export function formatPayrollGridHours(minutes: number): string {
  if (minutes <= 0) return ''
  return minutes % 60 === 0
    ? String(minutes / 60)
    : `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`
}

export interface PayrollGridCellState {
  /** Odpracované minuty daného dne v dané kategorii (net, tj. bez přestávky). */
  minutes: number
  /** Jediný zápis, který se dá z mřížky přepsat; `null` u prázdného dne. */
  entry: PayrollTimeEntry | null
  /**
   * Den má víc zápisů téže kategorie (dopoledne + odpoledne, směna přes
   * půlnoc). Součet se ukáže, ale přepsat ho z mřížky nejde — jedno číslo by
   * dva intervaly nahradilo jedním a tiše zahodilo přestávku mezi nimi.
   */
  locked: boolean
}

export function payrollGridCellState(
  entries: readonly PayrollTimeEntry[],
  date: string,
  category: PayrollTimeCategory,
): PayrollGridCellState {
  // Nahrazené revize sem nechodí — přehled je filtruje už dotazem
  // (`status <> 'superseded'`), takže `entries` nese jen platný stav měsíce.
  const matching = entries.filter(entry =>
    entry.category === category
    && entry.starts_at.slice(0, 10) === date)
  const minutes = matching.reduce((sum, entry) => sum + entry.net_minutes, 0)
  return {
    minutes,
    entry: matching.length === 1 ? matching[0] : null,
    locked: matching.length > 1,
  }
}

/** Odpracovaná doba dne = `regular + overtime`. Příznaky se nesčítají. */
export function payrollGridWorkedMinutes(
  entries: readonly PayrollTimeEntry[],
  date: string,
): number {
  return PAYROLL_WORKED_CATEGORIES.reduce(
    (sum, category) => sum + payrollGridCellState(entries, date, category).minutes,
    0,
  )
}

/** Které příznaky den nese — kvůli značce v buňce, ne kvůli součtu. */
export function payrollGridFlags(
  entries: readonly PayrollTimeEntry[],
  date: string,
): PayrollTimeCategory[] {
  return PAYROLL_FLAG_CATEGORIES.filter(
    category => payrollGridCellState(entries, date, category).minutes > 0,
  )
}

export type PayrollGridCellProblem =
  | 'unparsable'
  | 'delete_unsupported'
  | 'locked'
  | 'crosses_midnight'
  | 'invalid_wall_time'

export interface PayrollGridDraft {
  employmentId: number
  date: string
  raw: string
}

export interface PayrollGridBuildInput {
  drafts: readonly PayrollGridDraft[]
  category: PayrollTimeCategory
  /** `HH:MM` — od kdy se den zapisuje. */
  startTime: string
  breakMinutes: number
  timezone: string
  /** Zápisy a verze měsíce podle vztahu. */
  context: ReadonlyMap<number, {
    entries: readonly PayrollTimeEntry[]
    monthRowVersion: number
    open: boolean
  }>
}

export interface PayrollGridBuildResult {
  cells: PayrollTimeBatchCell[]
  /** Buňka → důvod, proč se neposlala. Klíč je `employmentId|date`. */
  problems: Map<string, PayrollGridCellProblem>
  /** Index v `cells` → klíč buňky, aby se `failures` z odpovědi trefily zpět. */
  keys: string[]
}

export function payrollGridCellKey(employmentId: number, date: string): string {
  return `${employmentId}|${date}`
}

function wallTimeMinutes(value: string): number | null {
  const match = /^(\d{1,2}):([0-5]\d)$/.exec(value.trim())
  if (!match) return null
  const minutes = Number(match[1]) * 60 + Number(match[2])
  return minutes >= 24 * 60 ? null : minutes
}

function wallTime(date: string, minutesOfDay: number): string {
  const hours = String(Math.floor(minutesOfDay / 60)).padStart(2, '0')
  const minutes = String(minutesOfDay % 60).padStart(2, '0')
  return `${date}T${hours}:${minutes}`
}

/**
 * Rozpracované buňky na dávku pro server.
 *
 * Posílají se JEN skutečné změny. Kdyby se posílalo všechno, „vyplnit pracovní
 * dny" na stránce dvaceti lidí by pokaždé přepsalo pět set beze změny stojících
 * dnů — každý jako nová revize zápisu, každý jako zvednutí verze měsíce.
 */
export function buildPayrollGridBatch(input: PayrollGridBuildInput): PayrollGridBuildResult {
  const cells: PayrollTimeBatchCell[] = []
  const keys: string[] = []
  const problems = new Map<string, PayrollGridCellProblem>()
  const startMinutes = wallTimeMinutes(input.startTime) ?? 8 * 60
  const breakMinutes = Math.max(0, Math.trunc(input.breakMinutes))

  for (const draft of input.drafts) {
    const key = payrollGridCellKey(draft.employmentId, draft.date)
    const context = input.context.get(draft.employmentId)
    if (!context || !context.open) continue
    const state = payrollGridCellState(context.entries, draft.date, input.category)
    const parsed = parsePayrollGridHours(draft.raw)
    if (parsed === false) {
      problems.set(key, 'unparsable')
      continue
    }
    if (state.locked) {
      problems.set(key, 'locked')
      continue
    }
    if (parsed === null || parsed === 0) {
      // Prázdno nad existujícím zápisem není „nula hodin": zápis se z mřížky
      // nemaže, protože smazání je revize s důvodem, ne prázdné políčko.
      if (state.minutes > 0) problems.set(key, 'delete_unsupported')
      continue
    }
    if (parsed === state.minutes) continue
    if (startMinutes + parsed + breakMinutes > 24 * 60) {
      problems.set(key, 'crosses_midnight')
      continue
    }
    const startsAt = payrollWallTimeToIso(wallTime(draft.date, startMinutes), input.timezone)
    const endsAt = payrollWallTimeToIso(
      wallTime(draft.date, startMinutes + parsed + breakMinutes),
      input.timezone,
    )
    if (startsAt === '' || endsAt === '') {
      // Přechod na letní čas: 2:30 toho dne prostě neexistuje.
      problems.set(key, 'invalid_wall_time')
      continue
    }
    keys.push(key)
    cells.push({
      employment_id: draft.employmentId,
      category: input.category,
      starts_at: startsAt,
      ends_at: endsAt,
      timezone: input.timezone,
      break_minutes: breakMinutes,
      supersedes_id: state.entry?.id ?? null,
      row_version: state.entry?.row_version ?? 0,
      month_row_version: context.monthRowVersion,
    })
  }
  return { cells, problems, keys }
}

export type PayrollGridMoveKey =
  | 'ArrowUp'
  | 'ArrowDown'
  | 'ArrowLeft'
  | 'ArrowRight'
  | 'Enter'
  | 'Home'
  | 'End'

export interface PayrollGridPosition {
  row: number
  column: number
}

/**
 * Kam se má mřížka posunout po stisku klávesy.
 *
 * Enter je záměrně „o řádek níž", ne „uložit": při zápisu docházky se jde po
 * lidech u téhož dne stejně často jako po dnech téhož člověka, a odeslání
 * formuláře Enterem uprostřed sedmi set políček by uložilo rozdělanou práci.
 * Uložení má Ctrl+Enter a tlačítko. `null` znamená „zůstaň, kde jsi" — na kraji
 * mřížky se nikam neskáče, aby kurzor nepřeskakoval mezi zaměstnanci.
 */
export function payrollGridNextPosition(
  position: PayrollGridPosition,
  key: PayrollGridMoveKey,
  rows: number,
  columns: number,
): PayrollGridPosition | null {
  if (rows <= 0 || columns <= 0) return null
  const clamped = (value: number, max: number) => Math.max(0, Math.min(max - 1, value))
  const next: PayrollGridPosition = { ...position }
  switch (key) {
    case 'ArrowUp':
      next.row = position.row - 1
      break
    case 'ArrowDown':
    case 'Enter':
      next.row = position.row + 1
      break
    case 'ArrowLeft':
      next.column = position.column - 1
      break
    case 'ArrowRight':
      next.column = position.column + 1
      break
    case 'Home':
      next.column = 0
      break
    case 'End':
      next.column = columns - 1
      break
  }
  next.row = clamped(next.row, rows)
  next.column = clamped(next.column, columns)
  if (next.row === position.row && next.column === position.column) return null
  return next
}

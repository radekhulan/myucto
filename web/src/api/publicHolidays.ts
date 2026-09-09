import { api } from './client'

/**
 * Číselník státních a ostatních svátků (z. č. 245/2000 Sb.).
 *
 * Řádek není datum, ale PRAVIDLO: pevný den v roce (`fixed` + `month_day`), nebo
 * posun ode dne Velikonoční neděle (`easter` + `easter_offset`). Datum Velikonoc
 * se počítá, protože ho neurčuje zákon, ale gregoriánský computus.
 */
export type PublicHolidayRuleType = 'fixed' | 'easter'

export interface PublicHoliday {
  id: number
  code: string
  name: string
  rule_type: PublicHolidayRuleType
  /** MM-DD — vyplněné právě pro `fixed`. */
  month_day: string | null
  /** Posun ve dnech od Velikonoční neděle — vyplněný právě pro `easter`. */
  easter_offset: number | null
  valid_from: string
  /** NULL = platí dosud. */
  valid_to: string | null
  note: string | null
  updated_at: string
}

export interface PublicHolidayPreviewDay {
  date: string
  code: string
  name: string
}

export interface PublicHolidaysResponse {
  rules: PublicHoliday[]
  rule_types: PublicHolidayRuleType[]
  /** Zápis do globálního číselníku smí jen správce instance. */
  can_write: boolean
  year: number
  /** Číselník rozpočítaný na konkrétní rok. */
  preview: PublicHolidayPreviewDay[]
  /** true = tabulka je prázdná a lhůty se počítají z pojistky zapečené v kódu. */
  fallback: boolean
}

export interface PublicHolidayInput {
  code: string
  name: string
  rule_type: PublicHolidayRuleType
  month_day?: string | null
  easter_offset?: number | null
  valid_from: string
  valid_to?: string | null
  note?: string | null
}

const BASE = '/codebooks/public-holidays'

export const publicHolidaysApi = {
  list: (year?: number) =>
    api.get<PublicHolidaysResponse>(BASE, { params: year ? { year } : {} }).then(r => r.data),
  create: (data: PublicHolidayInput) =>
    api.post<{ id: number; rules: PublicHoliday[] }>(BASE, data).then(r => r.data),
  update: (id: number, data: PublicHolidayInput) =>
    api.put<{ id: number; rules: PublicHoliday[] }>(`${BASE}/${id}`, data).then(r => r.data),
  remove: (id: number) =>
    api.delete<{ ok: boolean; rules: PublicHoliday[] }>(`${BASE}/${id}`).then(r => r.data),
}

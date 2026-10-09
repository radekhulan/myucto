/**
 * Převzatá část roku přechodu — typy nálezů, které vrací vyúčtování daně,
 * uzávěrka mzdového roku a kontrola převzetí.
 */

/** Osoba, které v převzatém měsíci trval vztah, ale počáteční stav ho nemá. */
export interface TakeoverGap {
  employee_id: number
  employee_name: string
  missing_months: number[]
}

export type TakeoverLayerMetric =
  | 'tax_base'
  | 'advance_tax'
  | 'withholding_tax'
  | 'tax_bonus'
  | 'social_base'
  | 'health_base'

/** Rozdíl mezi počátečním stavem (A) a převzatou mzdou (B) za týž měsíc. */
export interface TakeoverLayerDifference {
  employee_id: number
  employee_name: string
  period: string
  metric: TakeoverLayerMetric
  opening_minor: number
  takeover_minor: number
  difference_minor: number
}

/** Měsíce, které má jen jedna z vrstev. */
export interface TakeoverLayerOneSided {
  employee_id: number
  employee_name: string
  periods: string[]
}

/**
 * Vztah s nástupem jen odhadnutým z nejstaršího převzatého hlášení — mohl trvat
 * už v dřívějších převzatých měsících, za které převzaté mzdy chybí.
 */
export interface TakeoverEstimatedStart {
  employee_id: number
  employee_name: string
  employment_id: number
  start_on: string
  possible_months: number[]
}

/**
 * Převzatý pracovní poměr s důvodem slevy na pojistném, ke kterému chybí
 * přijatý záměr OZUSPOJ; sleva se bez něj neuplatní (§ 7a odst. 5).
 */
export interface TakeoverMissingDiscountIntent {
  employee_id: number
  employee_name: string
  employment_id: number
  employment_code: string
  discount_reason: string
}

export type TakeoverJmhzFormMetric =
  | 'gross'
  | 'social_base'
  | 'advance_tax'
  | 'health_insurance'

export interface TakeoverJmhzFormMetricDifference {
  metric: TakeoverJmhzFormMetric
  takeover_minor: number
  jmhz_minor: number
  difference_minor: number
}

/**
 * Přijaté hlášení JMHZ předchozího programu, které za osobu a měsíc nese jiné
 * údaje než převzatá (konečná) mzda — podnět k opravnému hlášení, ne chyba.
 */
export interface TakeoverJmhzFormDifference {
  employee_id: number
  employee_name: string
  period: string
  employment_ids: number[]
  submission_id: number
  submission_type: string | null
  submitted_at: string | null
  differences: TakeoverJmhzFormMetricDifference[]
}

export type TakeoverInvariantCode =
  | 'takeover_totals_without_employment'
  | 'takeover_relation_missing'
  | 'takeover_totals_mismatch'
  | 'takeover_source_rows_skipped'
  | 'takeover_duplicate_person'
  | 'takeover_terms_overlap'
  | 'takeover_component_overlap'
  | 'takeover_recurring_component_overlap'

/** Porušení invariantu převzetí; `text` je popis s osobními čísly a obdobími z převodu. */
export interface TakeoverInvariantViolation {
  code: TakeoverInvariantCode
  text: string
  context: Record<string, unknown>
}

/**
 * Invarianty převzetí: `live` počítá stránka z vlastních dat, `stored` jsou kontroly
 * proti zdroji uložené posledním převodem každého zdroje.
 */
export interface TakeoverInvariants {
  live: TakeoverInvariantViolation[]
  stored: Array<{ source: string, checked_at: string, violations: TakeoverInvariantViolation[] }>
}

export interface TakeoverCheck {
  takeover_months: number[]
  missing_openings: TakeoverGap[]
  /** Volitelné kvůli starší odpovědi bez klíče. */
  estimated_starts?: TakeoverEstimatedStart[]
  /** Volitelné kvůli starší odpovědi bez klíče. */
  missing_discount_intents?: TakeoverMissingDiscountIntent[]
  /** Volitelné kvůli starší odpovědi bez klíče. */
  jmhz_form_differences?: TakeoverJmhzFormDifference[]
  /** Brána G2; volitelné kvůli starší odpovědi bez klíče. */
  invariants?: TakeoverInvariants
  differences: TakeoverLayerDifference[]
  opening_only: TakeoverLayerOneSided[]
  takeover_only: TakeoverLayerOneSided[]
}

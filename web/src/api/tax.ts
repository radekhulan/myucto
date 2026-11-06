import { api } from './client'

export type FlatTaxBand = 'none' | 'band1' | 'band2' | 'band3'

export interface TaxProfile {
  activity_rate: number
  use_actual_expenses: boolean
  actual_expenses: number
  flat_tax_band: FlatTaxBand
  is_secondary: boolean
  spouse_credit: boolean
  children_count: number
  mortgage_interest: number
  /** Bytová potřeba obstaraná do 31. 12. 2020 → strop úroků 300k (§15/3-4 ZDP), jinak 150k */
  mortgage_pre_2021: boolean
  pension_contrib: number
  life_insurance: number
  dip_contrib: number
  long_term_care: number
  mortgage_months: number
  disability_12_months: number
  disability_3_months: number
  ztpp_months: number
  donations: number
  activities: TaxActivity[]
  children: TaxChild[]
  spouse_claim: SpouseClaim | null
  osvc_months: OsvcMonth[]
  saved?: boolean
}

export interface TaxActivity {
  name: string; nace_code: string; expense_mode: 'actual' | 'pausal'; expense_rate: number
  income: number; expenses: number; active_months: number; allocation_note?: string | null
}
export interface TaxChildMonth { month: number; order: number; ztpp: boolean; claimed: boolean }
/**
 * Rodné číslo dítěte a manžela/manželky (H28): server ho drží zapečetěné a vrací jen
 * masku. `birth_number` je jen vstup pro NOVÉ číslo; prázdné u položky s `id` uložené
 * číslo ponechá, `birth_number_clear` ho odebere. Plnou hodnotu vydá jen auditované
 * {@link taxApi.revealFamily}.
 */
export interface TaxFamilyIdentity {
  id?: number
  birth_number?: string | null
  birth_number_masked?: string | null
  has_birth_number?: boolean
  birth_number_clear?: boolean
}
export interface TaxChild extends TaxFamilyIdentity {
  first_name: string; last_name: string; birth_date?: string | null
  shared_household_proved: boolean; other_parent_not_claimed_proved: boolean; evidence_ref?: string | null
  months: TaxChildMonth[]
}
export interface SpouseClaim extends TaxFamilyIdentity {
  first_name: string; last_name: string; birth_date?: string | null
  eligible_months: number; ztpp: boolean; own_income: number; income_proved: boolean
  shared_household_proved: boolean; child_under_three_proved: boolean; evidence_ref?: string | null
}
export interface TaxFamilyReveal {
  year: number
  children: { id: number; birth_number: string }[]
  spouse: { id: number; birth_number: string } | null
}
export interface OsvcMonth {
  month: number; activity_status: 'inactive' | 'main' | 'secondary'; social_participates: boolean
  health_minimum_applies: boolean; state_insured: boolean; employed: boolean; new_osvc: boolean
  assessment_base?: number | null; note?: string | null
}

/**
 * Měsíční záloha paušální daně platná od `from` do dalšího segmentu. Sazba se může
 * změnit uprostřed roku (2026: 1. pásmo 9 984 → 9 162 Kč od 1. 7.), roční částka
 * v `pausal_annual` je z tohoto rozvrhu odvozená (backend `PausalSchedule`).
 */
export interface PausalSegment {
  from: string
  band1: number
  band2: number
  band3: number
}

/** Roční konstanty (tvar TaxConstants::forYear z backendu). Volné typování — engine je čte dynamicky. */
export interface TaxConstantsData {
  [key: string]: any
  year: number
  pausal_monthly: PausalSegment[]
  /** Odvozené (součet 12 měsíčních záloh roku) — read-only, neukládá se. */
  pausal_annual: Record<string, number>
  band_ceilings: Record<string, Record<string, number>>
  credit_taxpayer: number
  credit_spouse: number
  child_credits: number[]
  tax_rate_low: number
  tax_rate_high: number
  tax_high_threshold: number
  social_rate: number
  health_rate: number
  social_assessment_pct: number
  health_assessment_pct: number
  social_min_base_main: number
  social_min_base_secondary: number
  social_max_base: number
  /** Rozhodná částka (zisk) pro povinnou účast na důch. pojištění u vedlejší SVČ */
  social_secondary_participation_threshold: number
  health_min_base: number
  expense_caps: Record<string, number>
  mortgage_cap: number
  /** §15/3-4: strop úroků pro bytové potřeby obstarané do 31. 12. 2020 (300k) */
  mortgage_cap_pre2021: number
  pension_cap: number
  /** Dary §15/1 FO: spodní limit (Kč nebo 2 % ZD) a horní strop (% ZD) */
  donation_min_fo?: number
  donation_cap_fo_pct?: number
  vat_limit_low: number
  vat_limit_high: number
  /** DPH — platí pro všechny plátce (nejen OSVČ) */
  vat_rate_standard: number
  vat_rate_reduced: number
  kh_item_threshold: number
  child_bonus_min: number
  fixed_asset_limit: number
  transition_receivables_max_years: number
  nonprofit_reduction_rate: number
  nonprofit_reduction_min: number
  nonprofit_reduction_max: number
  nonprofit_reduction_university_min: number
  nonprofit_reduction_university_max: number
  tax_loss_carry_years: number
  tax_loss_carryback_years: number
  tax_loss_carryback_limit: number
}

/** Pravděpodobný čistý příjem za minulý kalendářní měsíc (odhad, viz TaxOptimizer::estimateMonthly). */
export interface LastMonthEstimate {
  ym: string
  revenue: number
  expenses: number
  profit: number
  income_tax: number
  social: number
  health: number
  net_income: number
}

export interface TaxAnalysis {
  year: number
  mode: 'retrospective' | 'forecast'
  profile: TaxProfile
  is_vat_payer: boolean
  supplier_band: FlatTaxBand
  constants: TaxConstantsData
  available_years: number[]
  income?: number
  ytd_income?: number
  months_elapsed?: number
  /** YoY: příjem + konstanty předchozího roku (jen v retrospektivě, pokud loni byl příjem). */
  prev?: { year: number; income: number; constants: TaxConstantsData } | null
  last_month: LastMonthEstimate
}

export const taxApi = {
  analysis: (year: number) =>
    api.get<TaxAnalysis>('/tax/analysis', { params: { year } }).then(r => r.data),
  saveProfile: (payload: Partial<TaxProfile> & { year: number }) =>
    api.put<{ profile: TaxProfile }>('/tax/profile', payload).then(r => r.data.profile),
  /**
   * Odhalení rodných čísel rodiny z daňového profilu. `reason` jde do auditní stopy;
   * formuláři stačí konstantní důvod (kdo a kdy plyne ze záznamu), stejně jako mzdám.
   */
  revealFamily: (year: number) =>
    api.post<{ reveal: TaxFamilyReveal }>('/tax/profile/family-reveal', {
      year,
      reason: 'Zobrazení rodných čísel v daňovém profilu',
    }).then(r => r.data.reveal),
}

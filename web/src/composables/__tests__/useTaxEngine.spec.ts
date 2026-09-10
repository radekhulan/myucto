import { describe, expect, it } from 'vitest'
import type { TaxConstantsData } from '@/api/tax'
import type { EngineProfile } from '@/composables/useTaxEngine'
import { pausal, predict } from '@/composables/useTaxEngine'

/**
 * Rady „odlož fakturu" a „příjem > X → nelze" jmenují práh v textu. Číslo do nich musí
 * jít z ročních konstant (`vat_limit_low` / strop pásma), ne ze zamrzlého literálu
 * v překladu — po změně limitu by uživateli tvrdily staré číslo.
 */
const constants = (over: Partial<TaxConstantsData> = {}): TaxConstantsData => ({
  year: 2025,
  pausal_monthly: [{ from: '2025-01-01', band1: 8716, band2: 16745, band3: 27139 }],
  pausal_annual: { band1: 104592, band2: 200940, band3: 325668 },
  band_ceilings: { 60: { band1: 1000000, band2: 1500000, band3: 2000000 } },
  credit_taxpayer: 30840,
  credit_spouse: 24840,
  child_credits: [15204, 22320, 27840],
  tax_rate_low: 0.15,
  tax_rate_high: 0.23,
  tax_high_threshold: 1676052,
  social_rate: 0.292,
  health_rate: 0.135,
  social_assessment_pct: 0.55,
  health_assessment_pct: 0.5,
  social_min_base_main: 195540,
  social_min_base_secondary: 78240,
  social_max_base: 2234736,
  social_secondary_participation_threshold: 111736,
  health_min_base: 250620,
  expense_caps: { 60: 1200000 },
  mortgage_cap: 150000,
  mortgage_cap_pre2021: 300000,
  pension_cap: 48000,
  vat_limit_low: 2000000,
  vat_limit_high: 2536500,
  vat_rate_standard: 21,
  vat_rate_reduced: 12,
  kh_item_threshold: 10000,
  child_bonus_min: 6000,
  fixed_asset_limit: 80000,
  transition_receivables_max_years: 3,
  ...over,
})

const profile = (over: Partial<EngineProfile> = {}): EngineProfile => ({
  activity_rate: 60,
  flat_tax_band: 'band1',
  is_vat_payer: false,
  is_secondary: false,
  spouse_credit: false,
  children_count: 0,
  mortgage_interest: 0,
  pension_contrib: 0,
  life_insurance: 0,
  donations: 0,
  ...over,
} as EngineProfile)

describe('predict — rada odložit fakturu', () => {
  it('nese práh, proti kterému se porovnávalo, ne zamrzlé 2 M', () => {
    const p = predict(profile(), 1_000_000, 6, constants())
    expect(p.deferMonth).toBe(12)
    expect(p.deferLimit).toBe(2_000_000)
  })

  it('po změně limitu v konstantách vrací nový práh', () => {
    const p = predict(profile(), 1_500_000, 6, constants({ vat_limit_low: 3_000_000 }))
    expect(p.deferMonth).toBe(12)
    expect(p.deferLimit).toBe(3_000_000)
  })

  it('bez překročení na konci roku práh nevrací', () => {
    const p = predict(profile(), 200_000, 6, constants())
    expect(p.deferMonth).toBeNull()
    expect(p.deferLimit).toBeNull()
  })
})

describe('pausal — zamítnutí over_2m', () => {
  it('vrací práh, o který se zamítnutí opřelo', () => {
    const r = pausal(profile(), 2_500_000, constants())
    expect(r.ok).toBe(false)
    expect(r.reason).toBe('over_2m')
    expect(r.ceiling).toBe(2_000_000)
  })

  it('práh se řídí konstantami roku', () => {
    const r = pausal(profile(), 3_500_000, constants({ vat_limit_low: 3_000_000 }))
    expect(r.ok).toBe(false)
    expect(r.ceiling).toBe(3_000_000)
  })
})

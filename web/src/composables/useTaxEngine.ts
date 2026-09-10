/**
 * TS port výpočetního jádra (zrcadlo api/src/Service/Tax/TaxOptimizer.php).
 * Slouží k okamžitému přepočtu při změně profilu na stránce — konstanty roku
 * (`c`) chodí z backendu (TaxConstants), takže existuje jediný zdroj čísel.
 * Autoritativní výpočet zůstává na backendu (PHP), tohle je pro svižné UI.
 */
import type { PausalSegment, TaxConstantsData, TaxProfile } from '@/api/tax'

export interface EngineProfile extends TaxProfile {
  is_vat_payer: boolean
}

const ORDER = ['band1', 'band2', 'band3'] as const

export interface BandResult {
  ok: boolean
  reason?: string
  declared?: string
  eff?: string
  ceiling?: number
  surcharge?: number
}

function effBand(declared: string, rate: number, income: number, c: TaxConstantsData): BandResult {
  if (declared === 'none' || !(declared in c.pausal_annual)) return { ok: false, reason: 'not_in_pausal' }
  // `ceiling` nese i zamítavá odpověď — text „příjem > X → nelze" musí jmenovat práh,
  // proti kterému se skutečně porovnávalo, ne zamrzlé číslo v překladu.
  if (income > c.vat_limit_low) return { ok: false, reason: 'over_2m', ceiling: c.vat_limit_low }
  const cl = c.band_ceilings[rate] || c.band_ceilings[40]
  let i = ORDER.indexOf(declared as typeof ORDER[number])
  while (i < 2 && income > cl[ORDER[i]]) i++
  const eff = ORDER[i]
  if (income > cl[eff]) return { ok: false, reason: 'over_2m', ceiling: cl[eff] }
  return {
    ok: true, declared, eff, ceiling: cl[eff],
    surcharge: eff !== declared ? c.pausal_annual[eff] - c.pausal_annual[declared] : 0,
  }
}

export interface PausalResult {
  ok: boolean
  reason?: string
  total: number | null
  eff?: string
  declared?: string
  surcharge?: number
  note?: boolean
  /** Práh, o který se zamítnutí opřelo (reason 'over_2m') — podklad pro text v UI. */
  ceiling?: number
}

export function pausal(p: EngineProfile, income: number, c: TaxConstantsData): PausalResult {
  if (p.is_vat_payer) return { ok: false, reason: 'vat_payer', total: null }
  const b = effBand(p.flat_tax_band, p.activity_rate, income, c)
  if (!b.ok) return { ok: false, reason: b.reason, total: null, ceiling: b.ceiling }
  return { ok: true, eff: b.eff, declared: b.declared, surcharge: b.surcharge, total: c.pausal_annual[b.eff!], note: b.eff !== b.declared }
}

export interface PausalPeriod { from: string; to: string; months: number; amount: number }

/**
 * Rozpad roku na období se stejnou měsíční zálohou (zrcadlo PausalSchedule::breakdown).
 * Segmenty z backendu jsou ukotvené k zobrazovanému roku a začínají 1. lednem.
 */
export function pausalPeriods(c: TaxConstantsData, band: string): PausalPeriod[] {
  const segs: PausalSegment[] = (c.pausal_monthly || []).filter(s => s && typeof s.from === 'string')
  if (!segs.length || !(band in (segs[0] as any))) return []
  const year = Number(segs[0].from.slice(0, 4))
  return segs.map((s, i) => {
    const startMonth = Number(s.from.slice(5, 7))
    const endMonth = i + 1 < segs.length ? Number(segs[i + 1].from.slice(5, 7)) - 1 : 12
    return {
      from: s.from,
      to: `${year}-${String(endMonth).padStart(2, '0')}-${new Date(year, endMonth, 0).getDate()}`,
      months: endMonth - startMonth + 1,
      amount: (s as any)[band] as number,
    }
  })
}

export interface PausalRateChange {
  from: string
  previousMonthly: number
  monthly: number
  monthsAtPrevious: number
  /** Přeplatek za měsíce zaplacené vyšší zálohou (jen při snížení sazby). */
  overpaid: number
  /** O přeplatek snížená nejbližší záloha (§ 38lk ZDP). */
  reducedAdvance: number
}

/** Poslední změna měsíční zálohy v roce — podklad pro upozornění v UI. */
export function pausalRateChange(c: TaxConstantsData, band: string): PausalRateChange | null {
  const p = pausalPeriods(c, band)
  if (p.length < 2) return null
  const last = p[p.length - 1]
  const prev = p[p.length - 2]
  const diff = prev.amount - last.amount
  if (diff <= 0) return null // zvýšení sazby přeplatek netvoří
  const monthsAtPrevious = p.slice(0, -1).reduce((s, x) => s + x.months, 0)
  const overpaid = Math.round(diff * monthsAtPrevious)
  return {
    from: last.from,
    previousMonthly: prev.amount,
    monthly: last.amount,
    monthsAtPrevious,
    overpaid,
    reducedAdvance: Math.max(0, Math.round(last.amount - overpaid)),
  }
}

function kidSum(n: number, arr: number[]): number {
  let s = 0
  for (let i = 1; i <= n; i++) s += arr[Math.min(i, arr.length) - 1]
  return s
}

export interface RegularResult {
  exp: number; ded: number; base: number; tax: number; kids: number
  incomeTax: number; isBonus: boolean; soc: number; hea: number; total: number
  net: number; eff: number; useActual: boolean
}

export function regular(p: EngineProfile, income: number, c: TaxConstantsData): RegularResult {
  // Skutečné výdaje (daňová evidence) NEBO výdajový paušál % se stropem.
  const exp = p.use_actual_expenses
    ? Math.max(0, p.actual_expenses || 0)
    : Math.min(income * p.activity_rate / 100, c.expense_caps[p.activity_rate])
  // §15 odpočty se zákonnými stropy (jeden zdroj pravdy s DpfoCalculator/DpfoReturnCalculator).
  // §15/3-4: strop úroků 300k pro bytovou potřebu obstaranou do 31. 12. 2020, jinak 150k.
  const mortgageCap = p.mortgage_pre_2021 ? (c.mortgage_cap_pre2021 ?? 300000) : c.mortgage_cap
  const uroky = Math.min(p.mortgage_interest, mortgageCap)
  // §15a: penzijko + životko sdílejí JEDEN společný strop 48 000 Kč (napřed penzijko, zbytek životko).
  const penzijko = Math.min(p.pension_contrib, c.pension_cap)
  const zivotko = Math.min(p.life_insurance || 0, Math.max(0, c.pension_cap - penzijko))
  // Dary §15/1: jen když ≥ spodní limit (1 000 Kč / 2 % ZD), max 30 % ZD (základ = §7 před §15).
  const section7Base = Math.max(0, income - exp)
  const donations = p.donations || 0
  const donationMin = Math.min(c.donation_min_fo ?? 1000, 0.02 * section7Base)
  const donationCap = (c.donation_cap_fo_pct ?? 0.30) * section7Base
  const dary = donations > 0 && donations >= donationMin ? Math.min(donations, donationCap) : 0
  const ded = uroky + penzijko + zivotko + dary
  const base = Math.max(0, income - exp - ded)
  const tax = base <= c.tax_high_threshold
    ? base * c.tax_rate_low
    : c.tax_high_threshold * c.tax_rate_low + (base - c.tax_high_threshold) * c.tax_rate_high
  const nonRef = c.credit_taxpayer + (p.spouse_credit ? c.credit_spouse : 0)
  const afterNonRef = Math.max(0, tax - nonRef)
  const kids = kidSum(p.children_count, c.child_credits)
  const incomeTax = afterNonRef - kids
  const profit = income - exp
  // Sociální 55 % zisku, zdravotní 50 % zisku (od 2024 odlišné), s ročními minimy.
  // Vedlejší SVČ pod rozhodnou částkou → sociální 0 (bez povinné účasti); u vedlejší se
  // navíc neuplatní minimální VZ zdravotního (souběh se zaměstnáním) — stejná pravidla
  // jako Social/HealthInsuranceCalculator (jeden zdroj pravdy).
  const soc = p.is_secondary && profit < (c.social_secondary_participation_threshold ?? 0)
    ? 0
    : Math.max(profit * c.social_assessment_pct, p.is_secondary ? c.social_min_base_secondary : c.social_min_base_main) * c.social_rate
  const hea = Math.max(profit * c.health_assessment_pct, p.is_secondary ? 0 : c.health_min_base) * c.health_rate
  const total = incomeTax + soc + hea
  // Čistý příjem = co reálně zbyde (paušální výdaje nejsou reálný výdaj); eff = odvody/příjem.
  return { exp, ded, base, tax, kids, incomeTax, isBonus: incomeTax < 0, soc, hea, total, net: income - total, eff: income > 0 ? total / income : 0, useActual: !!p.use_actual_expenses }
}

export interface CompareResult {
  pausal: PausalResult
  regular: RegularResult
  winner: 'pausal' | 'regular'
  delta: number | null
}

export function compare(p: EngineProfile, income: number, c: TaxConstantsData): CompareResult {
  const pa = pausal(p, income, c)
  const rg = regular(p, income, c)
  const winner: 'pausal' | 'regular' = !pa.ok ? 'regular' : (pa.total! <= rg.total ? 'pausal' : 'regular')
  const delta = pa.ok ? Math.round(rg.total - pa.total!) : null
  return { pausal: pa, regular: rg, winner, delta }
}

export interface Crossing { key: string; val: number; will: boolean; month: number | null }
export interface SecondarySocial { threshold: number; profit: number; will: boolean; month: number | null }
export interface PredictResult {
  run: number; proj: number; profit: number; cross: Crossing[]
  secondary: SecondarySocial | null; deferMonth: number | null; ytd: number; months: number
  /** Práh, který se v prosinci překročí (vat_limit_low daného roku) — text rady ho jmenuje. */
  deferLimit: number | null
}

export function predict(p: EngineProfile, ytd: number, months: number, c: TaxConstantsData): PredictResult {
  const run = months > 0 ? ytd / months : 0
  const proj = run * 12
  const T: { key: string; val: number }[] = []
  const cl = c.band_ceilings[p.activity_rate]
  if (p.flat_tax_band !== 'none' && cl && cl[p.flat_tax_band] != null) {
    T.push({ key: 'band', val: cl[p.flat_tax_band] })
  }
  T.push({ key: 'vatLow', val: c.vat_limit_low })
  T.push({ key: 'vatHigh', val: c.vat_limit_high })

  const cross: Crossing[] = T.map(t => {
    const m = run > 0 ? (t.val - ytd) / run + months : 99
    const will = proj >= t.val
    return { key: t.key, val: t.val, will, month: will ? Math.ceil(m) : null }
  })

  const v = cross.find(x => x.key === 'vatLow')
  const deferMonth = v && v.will && v.month! >= 11 && v.month! <= 12 ? v.month : null
  const deferLimit = deferMonth !== null && v ? v.val : null

  // Projekce ZISKU (příjmy − výdaje) pro limit vedlejší činnosti — výdaje dle profilu,
  // stejně jako regular(). Rozhodná částka se měří proti zisku, ne proti příjmu.
  const projExpenses = p.use_actual_expenses
    ? Math.max(0, p.actual_expenses || 0)
    : Math.min(proj * p.activity_rate / 100, c.expense_caps[p.activity_rate])
  const profit = Math.max(0, proj - projExpenses)

  // Vedlejší SVČ: rozhodná částka pro povinnou účast na důchodovém pojištění.
  let secondary: SecondarySocial | null = null
  const threshold = c.social_secondary_participation_threshold
  if (p.is_secondary && threshold) {
    const will = profit >= threshold
    const profitRun = profit / 12
    secondary = { threshold, profit, will, month: will && profitRun > 0 ? Math.ceil(threshold / profitRun) : null }
  }

  return { run, proj, profit, cross, secondary, deferMonth, deferLimit, ytd, months }
}

import { api } from './client'
import type { PayrollBenefitExemptionBasket } from './payroll'

// ── Koše osvobození benefitů ────────────────────────────────────────────────
// Čtecí přehled čerpání košů podle § 6 odst. 9 ZDP za celou firmu. Čísla jsou
// ZMRAZENÁ ze schválení mzdových vstupů — klient je nedopočítává a nesmí je
// dopočítávat ani zobrazením: jediné, co se odsud počítá, je procento pro
// ukazatel, a to čistě vizuálně.
//
// Rozhodné období si koš nevybírá, plyne z ustanovení. Proto dvě neprotínající
// se množiny košů a dva režimy dotazu: roční (`year`) a měsíční (`period`).

// Koš je tentýž typ, jaký nese katalog složek (`payroll.ts`) — vlastní kopie
// unionu by se s ním rozešla, jakmile zákon přidá další bod § 6 odst. 9.
export const BENEFIT_EXEMPTION_BASKETS: PayrollBenefitExemptionBasket[] = [
  'non_cash_health',
  'non_cash_leisure',
  'old_age_savings',
]

/** Koše, jejichž rozhodným obdobím je kalendářní měsíc (§ 6 odst. 9 písm. b) a i)). */
export const MONTHLY_BENEFIT_EXEMPTION_BASKETS: PayrollBenefitExemptionBasket[] = [
  'meal_per_shift',
  'temporary_accommodation',
]

/**
 * Stav řádku. `exceeded` vychází ze zmrazené nadlimitní části, takže platí
 * i bez dnešního limitu; `limit_unavailable` a `incomplete` jsou přiznání, že
 * podklad chybí — ne nula.
 */
export type BenefitBasketStatus =
  | 'ok'
  | 'approaching'
  | 'exceeded'
  | 'incomplete'
  | 'limit_unavailable'

/**
 * Za jaké období platí LIMIT řádku — což NENÍ totéž jako období součtu.
 *
 * `per_shift` je past § 6 odst. 9 písm. b) ZDP: sčítá se měsíc (mzdový vstup je
 * měsíční), ale limit je za jednu směnu. Měsíční součet se proti němu poměřit
 * nedá, takže `limit_minor` je u takového řádku vždy `null` a obrazovka to musí
 * říct větou — jinak by prázdný limit vypadal jako „v pořádku".
 */
export type BenefitBasketLimitBasis = 'tax_year' | 'calendar_month' | 'per_shift'

export interface BenefitBasketUsage {
  employee_id: number
  /**
   * Účinné jméno osoby. Citlivý identifikátor to podle § 58.17 manuálu není;
   * rodné číslo, adresa ani účet se odsud nevydávají.
   */
  employee_name: string
  basket: PayrollBenefitExemptionBasket
  statute: string
  /** Za jaké období limit platí. U `per_shift` je `limit_minor` vždy `null`. */
  limit_basis: BenefitBasketLimitBasis
  /**
   * Limit koše za jeho rozhodné období z rulesetu; `null` = netvrdí se — buď ho
   * ruleset pro to období nemá, nebo je limit za směnu a měsíční součet se
   * proti němu poměřit nedá.
   */
  limit_minor: number | null
  /** Úhrn HRUBÝCH plnění, kterými se koš čerpá. */
  used_minor: number
  /** Zmrazený součet osvobozených částí. */
  exempt_minor: number
  /** Zmrazený součet nadlimitních částí — to, co se už zdanilo. */
  taxable_minor: number
  /** `null`, když limit není znám. Nula znamená vyčerpáno, ne „nevíme". */
  remaining_minor: number | null
  input_count: number
  /** Kolik vstupů nemá zmrazený rozpad — chybějící podklad se nedopočítává. */
  unfrozen_count: number
  /** Kolik akumulátorů se uvolnilo stornem — do `used_minor` nevstupují. */
  reversed_count: number
  /** Úhrn plnění uvolněných stornem. */
  reversed_minor: number
  /** Čerpání v měsících převzatých z jiného mzdového programu — je součástí `used_minor`. */
  taken_over_minor: number
  /** Počet převzatých měsíců s čerpáním. */
  taken_over_months: number
  status: BenefitBasketStatus
  /** Zmrazený rozpad se rozešel s dnešním limitem (limit se v rulesetu změnil). */
  split_drift: boolean
}

export interface BenefitBasketOverview {
  items: BenefitBasketUsage[]
  total: number
  limit: number
  offset: number
  year: number
  /** Roky, ve kterých firma vůbec něco do koše načerpala. */
  years: number[]
}

export interface BenefitBasketMonthlyOverview {
  items: BenefitBasketUsage[]
  total: number
  limit: number
  offset: number
  /** Měsíc přehledu ve tvaru `YYYY-MM`. */
  period: string
  /** Měsíce, ve kterých firma vůbec něco do měsíčního koše načerpala. */
  periods: string[]
}

export interface BenefitBasketQuery {
  year: number
  basket?: PayrollBenefitExemptionBasket | ''
  q?: string
  limit?: number
  offset?: number
}

export interface BenefitBasketMonthlyQuery {
  /** `YYYY-MM`. Server neznámé ani prázdné období nedoplňuje aktuálním. */
  period: string
  basket?: PayrollBenefitExemptionBasket | ''
  q?: string
  limit?: number
  offset?: number
}

export const payrollBenefitBasketsApi = {
  overview: (query: BenefitBasketQuery) =>
    api
      .get<BenefitBasketOverview>('/payroll/benefit-baskets', {
        params: {
          year: String(query.year),
          ...(query.basket ? { basket: query.basket } : {}),
          ...(query.q ? { q: query.q } : {}),
          ...(query.limit === undefined ? {} : { limit: String(query.limit) }),
          ...(query.offset ? { offset: String(query.offset) } : {}),
        },
      })
      .then(r => r.data),

  monthlyOverview: (query: BenefitBasketMonthlyQuery) =>
    api
      .get<BenefitBasketMonthlyOverview>('/payroll/benefit-baskets', {
        params: {
          period: query.period,
          ...(query.basket ? { basket: query.basket } : {}),
          ...(query.q ? { q: query.q } : {}),
          ...(query.limit === undefined ? {} : { limit: String(query.limit) }),
          ...(query.offset ? { offset: String(query.offset) } : {}),
        },
      })
      .then(r => r.data),
}

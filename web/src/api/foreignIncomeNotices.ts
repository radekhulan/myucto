import { api } from './client'
import { downloadApiFilePost } from '@/utils/downloadFile'

/**
 * Písemnosti k příjmům daňových nerezidentů:
 *
 * - `dpshl1` — oznámení o příjmech plynoucích do zahraničí (§ 38da ZDP)
 * - `dpszd1` — hlášení o srážce zajištění daně (§ 38e ZDP)
 *
 * Obě jsou událostní: podávají se za jednu platbu, ne za zdaňovací období.
 * Věcnou část zadává uživatel — aplikace platby nerezidentům se srážkovou daní
 * ani zajištěním daně neeviduje a domýšlet je by znamenalo podat nepravdu.
 */
export type ForeignIncomeForm = 'dpshl1' | 'dpszd1'

/** `hl_typ` — řádné nebo následné podání. Shodné pro obě písemnosti. */
export type ForeignIncomeVariant = 'R' | 'N'

/** `zp_uhrady` — úhrada poplatníkovi vs. zaúčtování závazku. */
export type ForeignIncomePaymentMode = 'U' | 'Z'

/** `typ_popl` — typ poplatníka podle číselníku tiskopisu DPSHL1. */
export type ForeignPayeeType = '01' | '02' | '03' | '04' | '05' | '06'

/** `typ_dic` — DIČ, rodné číslo, sociální pojištění, jiné. */
export type ForeignTaxIdType = 'D' | 'R' | 'S' | 'J'

/** `typ_adr` — bydliště, sídlo, nespecifikováno. */
export type ForeignAddressType = '01' | '02' | '03'

/**
 * `sazba` v DPSZD1 — zástupný znak, ne číslo. A = 1 %, B = 10 %,
 * C a D jsou odkazy na § 16 a § 21, E = 0 % jen v následném hlášení.
 */
export type TaxSecurityRate = 'A' | 'B' | 'C' | 'D' | 'E'

export interface ForeignIncomeKind {
  /** `c_druh_prij` — číslo záznamu číselníku, jde do `druh_prij`. */
  code: number
  /** `k_rozl_prij` — skupina TÉHOŽ záznamu, nikdy se neodvozuje jinak. */
  group: string
  label: string
  paragraph: string
  effective_from: string
  /** Smí se u tohoto druhu oznámit osvobozený příjem se sazbou 0? */
  allows_exempt: boolean
}

export interface ForeignIncomeCatalog {
  income_kinds: ForeignIncomeKind[]
  taxpayer_types: ForeignPayeeType[]
  tax_id_types: ForeignTaxIdType[]
  address_types: ForeignAddressType[]
  notice_variants: ForeignIncomeVariant[]
  payment_modes: ForeignIncomePaymentMode[]
  security_rates: TaxSecurityRate[]
}

export interface ForeignPayeePayload {
  taxpayer_type: ForeignPayeeType
  first_name?: string | null
  last_name?: string | null
  company_name?: string | null
  birth_date?: string | null
  tax_id?: string | null
  tax_id_type?: ForeignTaxIdType | null
  tax_id_country?: string | null
  residence_country: string
  city: string
  postal_code?: string | null
  street?: string | null
  address_type?: ForeignAddressType
  birth_place?: string | null
  birth_country?: string | null
}

export interface ForeignIncomeRemittancePayload {
  paid_on: string
  amount_czk: number
  account?: string | null
}

export interface ForeignIncomeNoticePayload {
  variant: ForeignIncomeVariant
  discovered_on?: string | null
  payee: ForeignPayeePayload
  income_kind: number
  /** Sazba v desetinách procenta — 150 = 15,0 %. */
  rate_tenths_of_percent: number
  payment_mode: ForeignIncomePaymentMode
  payment_date?: string | null
  payment_year?: number | null
  paid_amount_minor: number
  tax_base_minor: number
  withheld_tax_czk: number
  withholding_due_on?: string | null
  remittance_due_on?: string | null
  gross_income_minor?: number | null
  mandatory_insurance_czk?: number | null
  foreign_gross_minor?: number | null
  foreign_gross_currency?: string | null
  payment_currency?: string | null
  /** Kurz v tisícinách — 25000 = 25,000. */
  exchange_rate_thousandths?: number | null
  note?: string | null
  remittances?: ForeignIncomeRemittancePayload[]
}

export interface TaxSecurityNoticePayload {
  variant: ForeignIncomeVariant
  payee: ForeignPayeePayload
  income_description: string
  rate: TaxSecurityRate
  income_minor: number
  secured_tax_czk: number
  receivable_on: string
  decisive_on: string
  remitted_on?: string | null
  permanent_establishment_address?: string | null
  note?: string | null
}

export type ForeignIncomePayload = ForeignIncomeNoticePayload | TaxSecurityNoticePayload

export const foreignIncomeNoticesApi = {
  catalog: () =>
    api.get<ForeignIncomeCatalog>('/tax/foreign-income/catalog').then(r => r.data),

  /**
   * XML se generuje POSTem, protože věcnou část nese tělo požadavku — do query
   * stringu se třicet položek včetně jména a adresy poplatníka nevejde a
   * osobní údaje do URL nepatří. Stahuje se proto přes blob, ne přes odkaz.
   */
  downloadXml: (form: ForeignIncomeForm, payload: ForeignIncomePayload) =>
    downloadApiFilePost(`/tax/foreign-income/${form}/xml`, payload, `${form}.xml`),
}

import axios from 'axios'
import type { PaymentMethod } from './invoices'

// Samostatný axios klient — web faktura je veřejná (bez přihlášení), proto
// neimplementujeme 401 redirect na /login (to dělá @/api/client). Vzor approval.ts,
// ale bez Accept-Language interceptoru: anonymní návštěvník nemá v localStorage
// smysluplnou volbu jazyka — prohlížeč pošle vlastní Accept-Language sám a jazyk
// obsahu se řídí invoice.language z dat.
const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: false,
  headers: {
    'Accept': 'application/json',
  },
})

export interface PublicInvoiceItem {
  description: string
  quantity: number
  duration_minutes?: number | null
  unit: string | null
  unit_price_without_vat: number
  vat_rate_snapshot: number
  total_without_vat: number
  total_with_vat: number
  item_kind: 'standard' | 'discount' | string
}

export interface PublicInvoiceVatRow {
  vat_rate_id: number
  rate: number
  base: number
  vat: number
  total: number
}

export interface PublicInvoiceHeader {
  varsymbol: string | null
  /** Efektivní platební VS (#249) — samostatný, jinak číslice z čísla dokladu. */
  payment_varsymbol?: string
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'cancellation' | 'tax_document' | 'rent_calendar' | 'advance_calendar'
  status: 'issued' | 'sent' | 'reminded' | 'paid' | 'cancelled'
  payment_status: 'unpaid' | 'partially_paid' | 'paid' | 'overpaid' | null
  language: 'cs' | 'en'
  currency: string
  currency_decimals?: number | null
  issue_date: string
  tax_date: string | null
  due_date: string
  paid_at: string | null
  payment_method: PaymentMethod
  reverse_charge: boolean
  prices_include_vat: boolean
  note_above_items: string | null
  note_below_items: string | null
  amount_to_pay: number
  paid_total: number
  /** Zaplaceno pro klienta (InvoicePaidState) — status 'paid' s reálným doplatkem dává false. */
  is_paid?: boolean
  totals: {
    without_vat: number
    vat: number
    with_vat: number
    rounding: number
    advance_paid_amount: number
    amount_to_pay: number
    discount_percent: number
    discount_amount: number
  }
  vat_breakdown: PublicInvoiceVatRow[]
  czk_recap: {
    rate: number
    rate_date: string
    fallback_used: boolean
    total_without_vat_czk: number
    total_vat_czk: number
    total_with_vat_czk: number
  } | null
  items: PublicInvoiceItem[]
}

export interface PublicInvoiceParty {
  company_name?: string | null
  first_name?: string | null
  last_name?: string | null
  street?: string | null
  city?: string | null
  zip?: string | null
  country_name_cs?: string | null
  country_name_en?: string | null
  country_iso2?: string | null
  ic?: string | null
  dic?: string | null
  tax_number?: string | null
  is_vat_payer?: boolean | number | null
  commercial_register?: string | null
  email?: string | null
  web?: string | null
}

export interface PublicInvoiceBank {
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  bic: string | null
}

export interface PublicInvoiceAttachment {
  id: number
  original_name: string
  size_bytes: number
}

/**
 * Podklad pro doložku o odvodu daně v režimu OSS. `all_items` = celý doklad je v OSS
 * (jinak jen část plnění); prázdné `countries` = některý řádek nemá zemi spotřeby,
 * takže se státy nejmenují. Staví ho backend (OssInvoiceClause), aby náhled zněl
 * stejně jako PDF.
 */
export interface PublicInvoiceOssClause {
  all_items: boolean
  countries: { iso2: string; name_cs: string; name_en: string }[]
}

export interface PublicInvoiceData {
  isdoc_available: boolean
  invoice: PublicInvoiceHeader
  supplier: PublicInvoiceParty
  /** null = zjednodušený doklad bez odběratele (§ 30 ZDPH). */
  client: PublicInvoiceParty | null
  bank: PublicInvoiceBank | null
  qr_data_uri: string | null
  attachments: PublicInvoiceAttachment[]
  oss_clause: PublicInvoiceOssClause | null
}

export const publicInvoiceApi = {
  get: (token: string) =>
    publicApi.get<PublicInvoiceData>(`/invoice/${token}`).then(r => r.data),

  pdfUrl: (token: string, download: boolean = false) =>
    `/api/public/invoice/${token}/pdf${download ? '?download=1' : ''}`,

  isdocUrl: (token: string) =>
    `/api/public/invoice/${token}/isdoc`,

  attachmentUrl: (token: string, attId: number) =>
    `/api/public/invoice/${token}/attachment/${attId}`,
}

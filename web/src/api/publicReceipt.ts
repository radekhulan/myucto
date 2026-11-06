import axios from 'axios'

// Samostatný axios klient — veřejná účtenka je bez přihlášení, proto žádný 401
// redirect na /login (ten dělá @/api/client). Vzor publicInvoice.ts.
const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: false,
  headers: {
    'Accept': 'application/json',
  },
})

export interface PublicReceiptItem {
  description: string
  quantity: number
  unit: string | null
  unit_price_without_vat: number
  vat_rate_snapshot: number
  total_without_vat: number
  total_with_vat: number
  item_kind: 'standard' | 'discount' | 'text' | string
}

export interface PublicReceiptVatRow {
  rate: number
  base: number
  vat: number
  total: number
}

export interface PublicReceiptEetRecord {
  kind: 'sale' | 'correction'
  eic: string
  premises: number | null
  cash_register: string | null
  sequence_number: string | null
  sale_at: string
  total_amount: string
  pok: string | null
  confirmed: boolean
  playground: boolean
}

export interface PublicReceiptData {
  receipt: {
    number: string | null
    issue_date: string | null
    tax_date: string | null
    is_simplified: boolean
    language: 'cs' | 'en'
    currency: string
    currency_decimals: number
    prices_include_vat: boolean
    payment_method: string | null
    paid_at: string | null
    totals: {
      without_vat: number
      vat: number
      with_vat: number
      rounding: number
      amount_to_pay: number
    } | null
    vat_breakdown: PublicReceiptVatRow[]
    items: PublicReceiptItem[]
    expires_at: string
  }
  supplier: {
    company_name?: string | null
    street?: string | null
    city?: string | null
    zip?: string | null
    ic?: string | null
    dic?: string | null
    is_vat_payer?: boolean | number | null
    commercial_register?: string | null
    web?: string | null
  }
  /** null = koncový zákazník zjednodušeného dokladu (§ 30 ZDPH). */
  client: {
    company_name?: string | null
    first_name?: string | null
    last_name?: string | null
    ic?: string | null
    dic?: string | null
  } | null
  eet: PublicReceiptEetRecord[]
}

export const publicReceiptApi = {
  get: (token: string) =>
    publicApi.get<PublicReceiptData>(`/receipt/${token}`).then(r => r.data),

  pdfUrl: (token: string, download: boolean = false) =>
    `/api/public/receipt/${token}/pdf${download ? '?download=1' : ''}`,
}

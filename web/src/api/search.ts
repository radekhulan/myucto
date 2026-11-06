import { api } from './client'

export interface SearchClient {
  id: number
  company_name: string
  main_email: string | null
  is_customer: boolean
  is_vendor: boolean
}

export interface SearchInvoice {
  id: number
  varsymbol: string | null
  invoice_type: string
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

export interface SearchPurchaseInvoice {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  document_kind: string | null
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

/** Zaměstnanec z kterékoli firmy, kde má uživatel právo číst mzdy. */
export interface SearchEmployee {
  id: number
  supplier_id: number
  supplier_name: string
  full_name: string
  is_active: boolean
  code: string | null
}

export interface SearchResults {
  q: string
  clients: SearchClient[]
  invoices: SearchInvoice[]
  purchase_invoices: SearchPurchaseInvoice[]
  /** Starší server pole nevrací. */
  employees?: SearchEmployee[]
}

export const searchApi = {
  /** Globální vyhledávání — klienti/dodavatelé + vydané/přijaté faktury (dle čísla dokladu) + zaměstnanci napříč firmami. */
  query: (q: string) => api.get<SearchResults>('/search', { params: { q } }).then(r => r.data),
}

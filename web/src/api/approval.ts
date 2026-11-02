import type { WorkReportDisplayOptions } from './invoices'
import axios from 'axios'

// Samostatný axios klient — public schvalování má být dostupné i nepřihlášeným,
// proto neimplementujeme 401 redirect na /login (to dělá @/api/client).
const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: false,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

publicApi.interceptors.request.use((config) => {
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)
  return config
})

export interface PublicApprovalWorkReportItem {
  id: number
  description: string
  work_date: string | null
  hours: number | null
  rate: number | null
  total_amount: number | null
  order_index: number
}

export interface PublicApprovalWorkReportMaterial {
  id: number
  description: string
  quantity: number
  unit: string
  unit_price: number
  total_amount: number
  order_index: number
}

export interface PublicApprovalWorkReport {
  id: number
  invoice_id: number
  project_id: number
  title: string
  total_hours: number | null
  total_amount: number | null
  items: PublicApprovalWorkReportItem[]
  material_title: string | null
  material_total: number
  material_vat_rate_id: number | null
  materials: PublicApprovalWorkReportMaterial[]
  effective_display_options?: WorkReportDisplayOptions
}

export interface PublicApprovalInvoice {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note'
  currency: string
  language: 'cs' | 'en'
  client_company_name: string | null
  project_name: string | null
  total_with_vat: number | null
  amount_to_pay: number | null
  requested_at: string | null
}

/**
 * Branding dodavatele pro hlavičku veřejné stránky. Prázdný (samá null), když
 * dodavatel branding nemá zapnutý — pak se ukáže neutrální hlavička MyÚčto.cz.
 */
export interface PublicApprovalBranding {
  display_name: string | null
  accent_color: string | null
  /** URL vázaná na token, ne cesta v úložišti. */
  logo_url: string | null
}

export interface PublicApprovalData {
  /** `requested` = ke schválení; jinak už rozhodnuto a stránka jen shrne výsledek. */
  state: 'requested' | 'approved' | 'rejected'
  branding: PublicApprovalBranding
  invoice: PublicApprovalInvoice
  work_report: PublicApprovalWorkReport
  supplier_name: string
  captcha_site_key: string
  captcha_provider: 'turnstile' | 'none' | string
}

/**
 * Odpověď na už rozhodnutý odkaz. Endpoint vrací 200, ne 404 — schvalovatel si
 * odkaz z e-mailu běžně otevře podruhé a chybová hláška by vypadala jako porucha.
 * Výkaz ani captcha se v téhle větvi neposílají, proto samostatný typ.
 */
export interface PublicApprovalDecided {
  state: 'approved' | 'rejected'
  supplier_name: string
  branding: PublicApprovalBranding
  invoice: Pick<PublicApprovalInvoice, 'varsymbol' | 'language'>
  decided_at: string | null
  rejection_reason: string | null
}

export interface DecidePayload {
  decision: 'approve' | 'reject'
  decided_by_email?: string | null
  rejection_reason?: string | null  // povinné pro reject
  comment?: string | null            // volitelné pro approve (sdílí sloupec)
  cf_turnstile_response?: string | null
}

export interface DecideResult {
  decision: 'approved' | 'rejected'
  message: string
  sent_to?: string[]
  auto_send_error?: string
}

export const approvalApi = {
  get: (token: string) =>
    publicApi.get<PublicApprovalData | PublicApprovalDecided>(`/approval/${token}`).then(r => r.data),

  decide: (token: string, payload: DecidePayload) =>
    publicApi.post<DecideResult>(`/approval/${token}/decide`, payload).then(r => r.data),
}

import type { WorkReportDisplayOptions } from './invoices'
import axios from 'axios'
import { api } from './client'

/* ────────────────────────────────────────────────────────────────────────
 * Public part — náhled na výkaz práce přes tajný odkaz (bez přihlášení).
 * Vlastní axios klient: withCredentials=true kvůli cookie-relaci (po ověření
 * kódem); žádný 401→/login redirect (na to je @/api/client).
 * ──────────────────────────────────────────────────────────────────────── */
const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: true,
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

export interface WrPreviewItem {
  description: string
  work_date: string | null
  duration_minutes?: number | null
  hours: number | null
  rate: number | null
  total_amount: number
}

export interface WrPreviewMaterial {
  description: string
  quantity: number
  unit: string
  unit_price: number
  total_amount: number
}

export interface WrPreviewReport {
  invoice_id: number
  label: string | null
  date: string
  currency: string
  project_name: string | null
  title: string
  total_hours: number | null
  total_amount: number | null
  items: WrPreviewItem[]
  material_title: string | null
  material_total: number
  materials: WrPreviewMaterial[]
  effective_display_options?: WorkReportDisplayOptions
}

export interface WrSupplier {
  name: string
  company_name: string
  tagline: string | null
  street: string | null
  city: string | null
  zip: string | null
  country: string | null
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  email: string | null
  phone: string | null
  web: string | null
}

/** Odběratel v hlavičce náhledu — stejný rozsah, jaký o sobě klient vidí na faktuře. */
export interface WrClient {
  company_name: string
  street: string | null
  city: string | null
  zip: string | null
  country: string | null
  ic: string | null
  dic: string | null
}

export interface WrPreview {
  scope: 'client' | 'project'
  client_company_name: string
  client?: WrClient
  project_name: string | null
  language: 'cs' | 'en'
  supplier_name: string
  supplier?: WrSupplier
  accent_color: string | null
  /** Logo dodavatele jako data: URI (jen při zapnutém brandingu), jinak null → MyInvoice. */
  logo_src: string | null
  reports: WrPreviewReport[]
  total_hours: number | null
  totals_by_currency: Array<{ currency: string; total_amount: number }>
}

export interface WrPublicState {
  requires_auth: boolean
  preview?: WrPreview
  scope?: 'client' | 'project'
  language?: 'cs' | 'en'
  supplier_name?: string
  logo_src?: string | null
  masked_emails?: string[]
  captcha_site_key?: string
  captcha_provider?: 'turnstile' | 'none' | string
}

export const publicWorkReportApi = {
  get: (token: string) =>
    publicApi.get<WrPublicState>(`/work-report/${token}`).then(r => r.data),

  requestCode: (token: string, payload: { email: string; cf_turnstile_response?: string | null; resend?: boolean }) =>
    publicApi.post<{ ok: boolean; cooldown_remaining: number }>(`/work-report/${token}/request-code`, payload).then(r => r.data),

  verify: (token: string, payload: { email: string; code: string }) =>
    publicApi.post<{ ok: boolean; preview: WrPreview }>(`/work-report/${token}/verify`, payload).then(r => r.data),
}

/* ────────────────────────────────────────────────────────────────────────
 * Authenticated part — správa odkazu z detailu klienta/zakázky.
 * ──────────────────────────────────────────────────────────────────────── */
export type WrLinkScope = 'client' | 'project'

export interface WrLinkStatus {
  exists: boolean
  url?: string
  last_sent_at?: string | null
  last_viewed_at?: string | null
}

export interface WrLinkRecipients {
  to: string[]
  cc: string[]
  bcc: string[]
  resolved: Array<{ email: string; recipient: 'to' | 'cc' | 'bcc'; source: string; usage: string | null; label: string | null }>
}

export interface WrLinkSendPayload {
  to: string[]
  cc?: string[]
  bcc?: string[]
  note?: string | null
}

function prefix(scope: WrLinkScope, id: number): string {
  return scope === 'project' ? `/projects/${id}` : `/clients/${id}`
}

export const workReportLinkApi = {
  status: (scope: WrLinkScope, id: number) =>
    api.get<WrLinkStatus>(`${prefix(scope, id)}/work-report-link`).then(r => r.data),

  recipients: (scope: WrLinkScope, id: number) =>
    api.get<WrLinkRecipients>(`${prefix(scope, id)}/work-report-link/recipients`).then(r => r.data),

  send: (scope: WrLinkScope, id: number, payload: WrLinkSendPayload) =>
    api.post<{ sent_to: string[]; sent_at: string; url: string }>(`${prefix(scope, id)}/work-report-link/send`, payload).then(r => r.data),

  revoke: (scope: WrLinkScope, id: number) =>
    api.delete<{ ok: boolean; exists: boolean }>(`${prefix(scope, id)}/work-report-link`).then(r => r.data),
}

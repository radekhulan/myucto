import axios from 'axios'

/* ────────────────────────────────────────────────────────────────────────
 * Veřejná strana zabezpečeného doručení mzdového dokumentu — zaměstnanec
 * bez přihlášení. Vlastní axios klient stejně jako `workReportTracking.ts`:
 * withCredentials=true kvůli relaci po ověření kódem (HttpOnly cookie,
 * scoped na konkrétní token v cestě), žádný 401→/login redirect z @/api/client.
 *
 * TOKEN se čte jen z parametru trasy a posílá se jen jako součást URL těchto
 * volání — nikam jinam (log, schránka, jiná hlavička) nepatří.
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

export interface PayrollDocumentAccessDocument {
  kind: string
  period_start: string | null
  created_at: string | null
  size_bytes: number
  suggested_filename: string
}

/**
 * Stav stránky. Před ověřením záměrně skoupý — jen maskovaná adresa a
 * parametry ověřovacího kódu, nic o dokumentu ani o zaměstnanci.
 */
export interface PayrollDocumentAccessState {
  verified: boolean
  recipient_masked: string
  code_ttl_seconds: number
  resend_cooldown_seconds: number
  document?: PayrollDocumentAccessDocument
}

export interface PayrollDocumentAccessCodeResult {
  sent: boolean
  cooldown_remaining: number
}

function encodedToken(token: string): string {
  return encodeURIComponent(token)
}

export const payrollDocumentAccessApi = {
  get: (token: string) =>
    publicApi.get<PayrollDocumentAccessState>(
      `/payroll-document/${encodedToken(token)}`,
    ).then(r => r.data),

  requestCode: (token: string) =>
    publicApi.post<PayrollDocumentAccessCodeResult>(
      `/payroll-document/${encodedToken(token)}/request-code`,
    ).then(r => r.data),

  verify: (token: string, code: string) =>
    publicApi.post<PayrollDocumentAccessState>(
      `/payroll-document/${encodedToken(token)}/verify`,
      { code },
    ).then(r => r.data),

  /**
   * Adresa ke stažení. Vydává se navigací/odkazem (`<a :href>`), NE
   * fetch+blob — jen tak fungují cookie relace i `Content-Disposition`
   * z odpovědi přirozeně (viz `PublicPayrollDocumentAccessAction`).
   */
  downloadUrl: (token: string): string =>
    `/api/public/payroll-document/${encodedToken(token)}/download`,
}

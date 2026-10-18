import { api } from './client'
import { createMigrationApi } from './migrationApi'

export { retryDelay } from './chunkedUpload'

/**
 * Průvodce „Přechod z Money S3" — záloha agendy, náhled, sestavy z Money ke kontrole,
 * zkouška nanečisto a ostrý převod na pozadí. Stav běžícího převodu se čte přes
 * společné `/admin/imports/{id}` (fetchImportJob v api/imports.ts).
 */

export interface MoneyS3YearInfo {
  dir: string
  /** Účetní rok firmy, do kterého adresář ROK převod převede (label = rok začátku období). */
  fiscal_year: number | null
  /** Hranice období u firmy s hospodářským rokem; kalendářní firma je nedostává. */
  period_start?: string
  period_end?: string
  /** Účetní rok, jak ho vede Money (`Rok.DAT`); null = záloha ho nenese. */
  money_year: number | null
  /** Režim roku v Money: podvojné účetnictví, nebo daňová evidence; null = rok bez dat i nastavení. */
  accounting_mode: 'double_entry' | 'tax_evidence' | null
  journal_rows: number
  /** Živé řádky peněžního deníku daňové evidence. Pokud firma vede podvojné účetnictví, je hodnota nulová, protože peněžní deník se v něm nevede. */
  cashbook_rows: number
  opening_rows: number
  first_date: string | null
  last_date: string | null
  purchase_invoices: number
  issued_invoices: number
  cash_documents: number
  bank_documents: number
}

export interface MoneyS3Agenda {
  name: string
  ico: string
  dic: string
  street: string
  city: string
  zip: string
  version: string
  version_verified: boolean
  backup_at: string
  years: MoneyS3YearInfo[]
  partners: number
  warnings: { code: string; message: string }[]
  /** Agenda vedená v Money celá jako daňová evidence (převod do daňové evidence firmy). */
  tax_records?: boolean
}

export interface MoneyS3Message {
  level: 'error' | 'warning' | 'info'
  code: string
  message: string
  context?: Record<string, unknown>
}

export interface MoneyS3Upload {
  token: string
  file_name: string
  sha256: string
  uploaded_at: string
  uploaded_by: number
  agenda: MoneyS3Agenda
  preflight: MoneyS3Message[]
  reports: number[]
}

/** Záloha se ještě nahrává po částech nebo ji server na pozadí rozbaluje a čte. */
export interface MoneyS3UploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received: number
  job_id: number | null
  error: string | null
}

export function isUploadReady(upload: MoneyS3Upload | MoneyS3UploadPending): upload is MoneyS3Upload {
  return 'agenda' in upload
}

export type MoneyS3Triple = [number, number, number]

export interface MoneyS3Diff {
  account: string
  myucto: MoneyS3Triple
  money: MoneyS3Triple
}

export interface MoneyS3Step {
  key: string
  status: 'ok' | 'warning' | 'error' | 'running'
  counts: Record<string, number>
  /** `acceptable` = rozdíl k přijetí: bez přijetí chyba, s přijetím (ostrý převod) upozornění. */
  messages: { level: 'error' | 'warning' | 'info'; code: string; text: string; context: Record<string, unknown>; acceptable?: boolean }[]
}

/** Rozdíl, který ostrý převod vědomě přijal (sekce `accepted_differences` protokolu). */
export interface AcceptedDifference {
  step: string
  code: string
  text: string
  context: Record<string, unknown>
}

/** Doklad, jehož rozdíl proti deníku je už ve zdrojovém programu (převod ho převzal věrně). */
export interface SourceDifference {
  document_no: string
  difference: number
  reason?: 'advance_deduction' | 'amount'
}

export interface MoneyS3ReconciliationYear {
  year: number
  period_id: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  journal_diffs: MoneyS3Diff[]
  money_report: { accounts: number; skipped_lines: number; diffs: MoneyS3Diff[] } | null
  documents: { key: string; documents: number; journal: number; ok: boolean; source_differences?: SourceDifference[] }[]
}

export interface MoneyS3ClosingYear {
  year: number
  status: string
  accounts?: number
  diffs?: { account_code: string; expected: number; existing: number; difference: number }[]
  error?: string
}

export interface MoneyS3ProtocolData {
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  failure: string | null
  error?: string
  steps: MoneyS3Step[]
  /** Převod selhal JEN na rozdílech k přijetí. */
  acceptable_only?: boolean
  accepted_differences?: AcceptedDifference[]
  agenda?: MoneyS3Agenda
  reconciliation?: MoneyS3ReconciliationYear[]
  closing?: MoneyS3ClosingYear[]
  orphans?: { type: string; year: number; document_no: string; id: number }[]
  automation?: { during: string; restored: boolean; after: string | null }
  payroll_totals?: MoneyS3PayrollMonth[]
  /** Rekonciliace převedené daňové evidence: peněžní deník MyÚčta proti Money po letech. */
  tax_records_reconciliation?: MoneyS3TaxRecordsYear[]
}

/** Rok převedené daňové evidence: daňové příjmy a výdaje v MyÚčtu a v Money (Kč). */
export interface MoneyS3TaxRecordsYear {
  year: number
  ok: boolean
  money: { income_taxable: number; expense_taxable: number }
  myucto: { income_taxable: number; expense_taxable: number; unclassified: number }
  differences: number
  missing: number
  extra: number
  non_cash: number
}

/** Měsíční kontrolní úhrny mezd celé firmy z mzdových dokladů Money (Kč). */
export interface MoneyS3PayrollMonth {
  period: string
  gross: number
  employee_social: number
  employee_health: number
  employer_social: number
  employer_health: number
  advance_tax: number
  withholding_tax: number
  deductions: number
  net_payable: number
  /** Sražené zálohy, přeplatky z ročního zúčtování, odvod a zálohy po přeplatcích z vyúčtování Money. */
  dpfo: number | null
  dpfo_refunds: number | null
  dpfo_remitted: number | null
  dpfo_net: number | null
  tax_ok: boolean | null
}

export type MoneyS3RunStatus = 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'

export interface MoneyS3Run {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_name: string | null
  money_version: string | null
  created_at: string
  finished_at: string | null
  protocol?: MoneyS3ProtocolData | null
}

export interface MoneyS3StartParams {
  mode: 'dry_run' | 'import'
  close_history: boolean
  first_period_start: string | null
  /** Záloha patří firmě, i když to IČO ověřit nejde (chybí v záloze nebo ve firmě). */
  confirm_ico?: boolean
  /** První převáděný účetní rok; starší roky zálohy se vynechají. */
  from_year?: number | null
  /** Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto. */
  accept_differences?: boolean
}

const BASE = '/admin/imports/money-s3'

export const moneyS3Api = {
  ...createMigrationApi<MoneyS3Upload, MoneyS3UploadPending, MoneyS3Run, MoneyS3StartParams>(BASE),
  upload: (file: File): Promise<MoneyS3Upload> => {
    const fd = new FormData()
    fd.append('backup', file, file.name)
    return api.post<MoneyS3Upload>(`${BASE}/uploads`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
  attachReport: (token: string, year: number, file: File): Promise<{ year: number; accounts: number; skipped_lines: number }> => {
    const fd = new FormData()
    fd.append('year', String(year))
    fd.append('report', file, file.name)
    return api.post(`${BASE}/uploads/${token}/reports`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
}

import { api } from './client'

/**
 * Měsíční zaúčtování mezd z PAMICY (XML dataPack pro POHODU): náhled a načtení interního
 * dokladu a ostatních závazků. Volá ho průvodce PAMICA i import přijatých faktur, když
 * nahraný soubor rozpozná server jako zaúčtování mezd.
 */

export type PayrollPostingCheckLevel = 'ok' | 'warning' | 'error'

export interface PayrollPostingCheck {
  code: string
  level: PayrollPostingCheckLevel
  message: string
  context: Record<string, unknown>
}

export interface PayrollPostingLine {
  text: string
  note: string | null
  debit: string | null
  credit: string
  without_posting: boolean
  amount: number
}

export interface PayrollPostingInternalDocument {
  item_id: string
  title: string
  document_date: string
  accounting_date: string
  lines: PayrollPostingLine[]
  amount: number
}

export interface PayrollPostingCommitment {
  item_id: string
  title: string
  partner_name: string | null
  partner_kind: 'company' | 'person' | 'none'
  variable_symbol: string | null
  constant_symbol: string | null
  document_date: string
  accounting_date: string
  due_on: string
  payment_type: string | null
  account: string | null
  account_error: string | null
  posting: 'posted' | 'without_posting'
  credit: string
  lines: PayrollPostingLine[]
  amount: number
}

export interface PayrollPostingAccount {
  source: string
  synthetic: string
  target: string | null
  proposed: string | null
  status: 'ok' | 'creatable' | 'missing' | 'inactive' | 'not_liability' | 'has_analytics'
}

export interface PayrollPostingPreview {
  format: 'pamica_payroll_posting'
  source: {
    application: string
    ico: string
    pack_id: string | null
    sym_par: string
    period: string
    accounting_date: string
    document_date: string
  }
  internal_documents: PayrollPostingInternalDocument[]
  commitments: PayrollPostingCommitment[]
  accounts: PayrollPostingAccount[]
  totals: {
    internal: number
    commitments_posted: number
    commitments_without_posting: number
    commitments: number
  }
  checks: PayrollPostingCheck[]
  can_apply: boolean
  can_apply_with_new_accounts: boolean
  can_write: boolean
  can_create_accounts: boolean
}

export interface PayrollPostingResult {
  import_id: number
  sym_par: string
  period: string
  internal_documents: { id: number; document_no: string }[]
  other_items: { id: number; document_no: string | null; status: string; title: string; partner_name: string | null; amount: number }[]
  created_accounts: string[]
}

/** Kód chyby, kterým server říká, že soubor není zaúčtování mezd (průvodce pak pokračuje převodem). */
export const NOT_PAYROLL_POSTING = 'not_payroll_posting'

function form(file: File, extra: Record<string, string> = {}): FormData {
  const fd = new FormData()
  fd.append('file', file, file.name)
  for (const [key, value] of Object.entries(extra)) fd.append(key, value)
  return fd
}

export const payrollPostingImportsApi = {
  preview: (file: File) =>
    api.post<PayrollPostingPreview>('/accounting/payroll-posting-imports/preview', form(file),
      { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),
  apply: (file: File, createMissingAccounts: boolean) =>
    api.post<PayrollPostingResult>('/accounting/payroll-posting-imports/apply',
      form(file, createMissingAccounts ? { create_missing_accounts: '1' } : {}),
      { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),
}

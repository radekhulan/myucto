import { api } from './client'

/**
 * Featura E (REAL_data_followup_UX.md) — READ-ONLY kontrola úplnosti dokladů proti bance:
 * bankovní pohyby bez dokladu po prahu X dní (§24/1) + doklady bez úhrady po splatnosti.
 */
export type AgingBucket = 'd0_30' | 'd31_60' | 'd61_90' | 'd91_180' | 'd180_plus'
export type Direction = 'outgoing' | 'incoming' | 'all'

export interface BankWithoutDocumentItem {
  bank_transaction_id: number
  statement_id: number
  date: string
  days: number
  bucket: AgingBucket
  amount: number
  currency: string
  direction: 'outgoing' | 'incoming'
  counterparty: string | null
  counterparty_account: string | null
  variable_symbol: string | null
  description: string | null
  document_requested: boolean
}

export interface OverdueDocumentItem {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  account_code: string
  partner_name: string
  issue_date: string
  due_date: string
  days_overdue: number
  currency_code: string
  remaining_czk: number
}

export interface BucketSummary {
  bucket: AgingBucket
  count: number
  total_czk: number
}

export interface DocumentCompletenessResult {
  generated_at: string
  threshold_days: number
  direction: Direction
  bank_without_document: {
    items: BankWithoutDocumentItem[]
    summary: { total_count: number; total_czk: number; by_bucket: BucketSummary[] }
  }
  documents_overdue_unpaid: {
    items: OverdueDocumentItem[]
    /**
     * `truncated` = otevřených položek bylo na saldokontním účtu víc než 5 000;
     * seznam i součet pak platí jen za načtenou (nejstarší) část.
     */
    summary: { total_count: number; total_czk: number; truncated: boolean }
  }
}

export interface DocumentCompletenessParams {
  days?: number
  direction?: Direction
}

export const documentCompletenessApi = {
  get: (params: DocumentCompletenessParams = {}) =>
    api.get<DocumentCompletenessResult>('/accounting/reports/document-completeness', { params }).then(r => r.data),
}

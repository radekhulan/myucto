import type { SecuritiesAssessment, SecuritiesEvidence } from './securities504'
import { api } from './client'

export type Fx504Map<T> = Record<string, T> | []
export interface Fx504AccountDecision { classification: 'monetary' | 'nonmonetary'; reason: string }
export interface Fx504Cell { amount: string; dimensions: Record<string, number> }
export interface Fx504Decision { reason: string; cells: Fx504Cell[] }
export type Fx504CaseOrigin = { kind: 'document'; doc_type: 'invoice' | 'purchase_invoice'; doc_id: number }
  | { kind: 'declared_title'; origin_line_id: number; reference: string }
  | { kind: 'cash_account'; account_id: number; currency_code: string }
export interface Fx504CaseDecision { origin: Fx504CaseOrigin; reason: string; contributions: { cell_key: string; czk_signed: string; foreign_signed: string }[] }
export interface Fx504SourceCell { cell_key: string; line_id: number; entry_id: number; line_no: number; account_id: number; account_code: string; currency_code: string;
  dimensions: Fx504Map<number>; czk_signed: string; foreign_signed: string; candidates: Fx504CaseOrigin[] }
export interface Fx504Position {
  key: string; account_code: string; currency_code: string; booked_czk: string; foreign_balance: string
  dimensions: Fx504Map<number>; source_line_ids: number[]; diff: string | null; new_czk: string | null; rate: string | null
  classification: 'monetary' | 'nonmonetary' | 'securities' | null
  finding: 'monetary_title_required' | 'profit_dimensions_required' | 'rate_required' | 'securities_valuation_required' | 'case_evidence_required' | null
}
export interface Fx504Preview {
  securities: SecuritiesAssessment
  accounting_decree: '504'; snapshot_hash: string; positions: Fx504Position[]; complete: boolean
  totals: { loss: string; gain: string }; required_types: number[]
  dimension_types: { id: number; name: string; is_active: boolean | 0 | 1; system_key: string | null }[]
  dimension_values: { id: number; type_id: number; name: string; code: string; semantic_key?: string | null; is_active: boolean | 0 | 1 }[]
  case_decisions: Fx504CaseDecision[]; case_source_cells: Fx504SourceCell[]; case_unresolved: Fx504SourceCell[]; case_locked_cell_keys: string[]; case_findings: { code: string; entry_id?: number; line_id?: number; step_id?: number }[]
  account_decisions: Fx504Map<Fx504AccountDecision>; saved_decisions: Fx504Map<Fx504Decision>
  rate_info: { currency: string; rate: string; rate_date: string; fallback_used: boolean }[]
}
const scope = (supplierId: number) => ({ headers: { 'X-Supplier-Id': String(supplierId) } })
export const fx504Api = {
  preview: (supplierId: number, periodId: number, decisions?: Record<string, Fx504AccountDecision>) => api.get<Fx504Preview>(
    `/accounting/periods/${periodId}/closing/fx-preview`, { ...scope(supplierId), params: decisions === undefined ? {} : { account_decisions: JSON.stringify(decisions) } }).then(r => r.data),
  casePreview: (supplierId: number, periodId: number, body: { account_decisions: Record<string, Fx504AccountDecision>; case_decisions: Fx504CaseDecision[]; securities_evidence?: SecuritiesEvidence }) =>
    api.post<Fx504Preview>(`/accounting/periods/${periodId}/closing/fx-preview`, body, scope(supplierId)).then(r => r.data),
  run: (supplierId: number, periodId: number, body: { row_version: number; snapshot_hash: string; account_decisions: Record<string, Fx504AccountDecision>; decisions: Record<string, Fx504Decision>; case_decisions: Fx504CaseDecision[]; securities_evidence?: SecuritiesEvidence }) =>
    api.post(`/accounting/periods/${periodId}/closing/steps/fx_revaluation/run`, body, scope(supplierId)).then(r => r.data),
  revert: (supplierId: number, periodId: number, rowVersion: number) =>
    api.post(`/accounting/periods/${periodId}/closing/steps/fx_revaluation/revert`, { row_version: rowVersion }, scope(supplierId)).then(r => r.data),
  rate: (supplierId: number, currency: string, date: string) => api.get('/codebooks/cnb-rate', { ...scope(supplierId), params: { currency, date } }).then(r => r.data),
}

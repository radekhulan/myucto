import { api } from './client'
export interface LegalCell { dimensions: Record<number, number>; cost_center?: string | null; project_id?: number | null; amount: string | number; available_cents?: number }
export interface ReserveSource {
  asset_id: number; mode: 'capitalization' | 'documented_association'; allocation_method: 'capitalization_joint_proportional'; continued_use_evidence: string; source_hash?: string
  association?: { reference: string; evidence: string; contributions: { line_id: number; contribution_cents: number }[] }
}
export interface SourceReceipt { source_hash: string; asset_id: number; mode: ReserveSource['mode']; cells: (Omit<LegalCell, 'amount'> & { weight_cents: number })[]; contributions: { line_id: number; contribution_cents: number; dimensions: Record<number, number> }[] }
export interface SourceState { current: SourceReceipt | null; revisions: { revision_number: number; effective_on: string; reason: string; version: string }[]; blocking_entries: { entry_id: number; period_id: number; entry_date: string }[] }
export interface ReserveWorkspace {
  entries: { entry_id: number; document_no: string; rule_key: string; amount?: number; description?: string }[]
  assets: { id: number; name: string; inventory_number: string; input_price: string }[]; more_assets: boolean
  lines: { line_id: number; entry_id: number; document_no: string; entry_date: string; description: string; amount: string }[]; line_page: number; more_lines: boolean
  state: SourceState; dimension_labels: Record<number, string>; period: { row_version: number; status: string; ends_on: string }
}
export interface ReservePreview { source: SourceReceipt; allocations?: LegalCell[]; remaining_cents?: number; lines?: (LegalCell & { account_code: string; side: string })[]; revision_number?: number }
export interface OriginalPurpose { same_original_asset_and_purpose: boolean; document_date: string; reference: string; evidence: string }
export interface CorrectionRequest { mode: 'same_period' | 'prospective_closed_history'; previous_hash: string; reason: string; reserve_source: ReserveSource; original_purpose?: OriginalPurpose; row_version?: number }
export interface LegalDecision { reference: string; legal_basis: string; evidence: string; taxable_activity: boolean; conditions_confirmed: boolean; allocations?: LegalCell[]; reserve_source?: ReserveSource; source_entry_id?: number; allocation_basis?: 'source_joint' | 'new_decision'; payment_allocation?: 'proportional' }
export interface LegalProvisionItem { invoice_id: number; document_no: string; partner_name: string; remaining: number; existing: { legal_amount: number; allocations: LegalCell[] } | null; source_joint: { available: boolean; cells: (LegalCell & { available_cents: number })[]; finding?: { message: string } } }
export interface LegalProvisionsPreview { accounting_framework: 'cz_504'; as_of: string; items: LegalProvisionItem[]; reserves: { entry_id: number; document_no: string; reference: string; cells: (LegalCell & { available_cents: number })[] }[]; pagination: { page: number; per_page: number; total: number } }
const prefix = (period: number) => `/accounting/periods/${period}/closing`
export const legalClosing504Api = {
  ledgerWorkspace: (period: number) => api.get<ReserveWorkspace>(`${prefix(period)}/legal-provisions/workspace`).then(r => r.data),
  workspace: (period: number, params: { asset_id?: number; q?: string; line_q?: string; page?: number } = {}) => api.get<ReserveWorkspace>(`${prefix(period)}/asset-reserve/workspace`, { params }).then(r => r.data),
  preview: (period: number, reserve_source: ReserveSource, amount: string) => api.post<ReservePreview>(`${prefix(period)}/asset-reserve/preview`, { reserve_source, amount }).then(r => r.data),
  correctionPreview: (period: number, body: CorrectionRequest) => api.post<ReservePreview>(`${prefix(period)}/asset-reserve/correction-preview`, body).then(r => r.data),
  correct: (period: number, body: CorrectionRequest) => api.post<{ revision_number: number; entry_id?: number }>(`${prefix(period)}/asset-reserve/correct`, body).then(r => r.data),
  reserve: (period: number, body: { rule_key: 'reserve.repairs.create' | 'reserve.repairs.release'; amount: string; description: string; row_version: number; legal_decision: LegalDecision }) => api.post<{ entry_id: number }>(`${prefix(period)}/entries`, { ...body, step: 'provisions' }).then(r => r.data),
  provisions: (period: number, page = 1) => api.get<LegalProvisionsPreview>(`${prefix(period)}/provisions-preview`, { params: { page } }).then(r => r.data),
  provision: (period: number, row_version: number, item: { invoice_id: number; legal_amount: string; acct_amount: number; legal_decision: LegalDecision }) => api.post(`${prefix(period)}/steps/provisions/run`, { row_version, partial: true, items: [item] }).then(r => r.data),
  reverse: (period: number, entry: number, row_version: number) => api.post(`${prefix(period)}/entries/${entry}/reverse`, { row_version }).then(r => r.data),
}

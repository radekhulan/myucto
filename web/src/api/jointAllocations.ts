import { api } from './client'

export const jointAxes = ['activity', 'activity_kind', 'tax_regime'] as const
export type JointAxis = typeof jointAxes[number]
export interface JointCell {
  activity: number
  activity_kind: number
  tax_regime: number
  weight: string
  additional_dimensions?: Record<number, number>
}
export interface JointVersionInput {
  valid_from: string
  valid_to: string
  basis: 'hours' | 'area' | 'revenue' | 'fixed'
  explanation: string
  allocation_mode: 'weighted' | 'explicit_amounts'
  cells: JointCell[]
}
export interface JointVersion extends JointVersionInput {
  id: number
  key_id: number
  code: string
  name: string
  version_no: number
  cell_ids: string[]
}
export interface JointCandidate {
  id: number
  entry_id: number
  source_date: string
  source_type: string
  source_id: number | null
  document_no: string | null
  entry_description: string | null
  account_code: string
  account_name: string
  side: 'debit' | 'credit'
  amount: string
  amount_foreign: string | null
  currency_code: string | null
  fx_rate: string | null
  is_red_storno: 0 | 1
  dimensions: Record<number, number> | []
  dimension_splits: Record<number, Record<number, number>> | []
}
export interface ExactSourceCells {
  source_line_id: number
  evidence: string
  cells: { cell_id: string; amount: string; amount_foreign: string | null }[]
}
export interface JointPreviewInput {
  key_version_id: number
  target_date: string
  source_line_ids: number[]
  exact_cells: ExactSourceCells[]
}
export interface JointTarget {
  account_id: number
  side: string
  amount: string
  amount_foreign: string | null
  currency_code: string | null
  is_red_storno: boolean | 0 | 1
  dimensions: Record<number, number> | []
}
export interface JointPreview {
  preview_hash: string
  source_lines: JointCandidate[]
  target_date: string
  key: Omit<JointVersion, 'cell_ids'>
  exact_cells: Record<number, ExactSourceCells> | []
  semantic_values: Record<number, { name: string; code: string; system_key?: string | null; semantic_key?: string | null }>
  plan: { sources: { source_line_id: number; compensation: JointTarget; targets: JointTarget[] }[] }
}
export interface JointBatch {
  id: number
  target_date: string
  reason: string
  status: 'applied' | 'cancelled'
  journal_entry_id: number
  created_at: string
}
export interface JointDetail extends JointBatch {
  snapshot: JointPreview
  cancellation: { target_date: string; reason: string; reversal_entry_id: number } | null
  audit: { action: string; actor_source: string; created_at: string }[]
}
export interface JointMutationResult {
  id: number
  journal_entry_id: number
  key_version_id: number
  target_date: string
  preview_hash: string
  status: 'applied' | 'cancelled'
  cancellation_entry_id: number | null
}
export interface RevenueProposal {
  basis: 'revenue'
  date_from: string
  date_to: string
  net_revenue_cents: string
  source_lines: number
  unclassified_lines: number
  unclassified_amount_cents: string
  cells: JointCell[]
  can_propose: boolean
  reason_codes: string[]
  proposal_hash: string
  verification_scope: 'posted_revenue_aggregates'
  requires_manual_confirmation: true
  mutations_performed: false
}
const base = '/accounting/joint-allocations'
const scope = (supplierId: number) => ({ headers: { 'X-Supplier-Id': String(supplierId) } })
export const jointAllocationsApi = {
  async keys(supplierId: number): Promise<JointVersion[]> {
    return (await api.get(`${base}/keys`, scope(supplierId))).data
  },
  async createKey(supplierId: number, code: string, name: string, version: JointVersionInput) {
    return (await api.post(`${base}/keys`, { code, name, version }, scope(supplierId))).data
  },
  async createVersion(supplierId: number, keyId: number, version: JointVersionInput) {
    return (await api.post(`${base}/keys/${keyId}/versions`, version, scope(supplierId))).data
  },
  async candidates(supplierId: number, params: { target_date: string; from: string; to: string; search: string; before_id?: number }): Promise<{ items: JointCandidate[]; next_before_id: number | null }> {
    return (await api.get(`${base}/candidates`, { ...scope(supplierId), params })).data
  },
  async revenueProposal(supplierId: number, params: { from: string; to: string }): Promise<RevenueProposal> {
    return (await api.get(`${base}/revenue-proposal`, { ...scope(supplierId), params })).data
  },
  async preview(supplierId: number, input: JointPreviewInput): Promise<JointPreview> {
    return (await api.post(`${base}/preview`, input, scope(supplierId))).data
  },
  async apply(supplierId: number, input: JointPreviewInput & { preview_hash: string; reason: string; idempotency_key: string }): Promise<JointMutationResult> {
    return (await api.post(`${base}/apply`, input, scope(supplierId))).data
  },
  async batches(supplierId: number, beforeId?: number): Promise<JointBatch[]> {
    return (await api.get(`${base}/batches`, { ...scope(supplierId), params: { before_id: beforeId } })).data
  },
  async detail(supplierId: number, id: number): Promise<JointDetail> {
    return (await api.get(`${base}/batches/${id}`, scope(supplierId))).data
  },
  async cancel(supplierId: number, id: number, input: { target_date: string; reason: string; idempotency_key: string }): Promise<JointMutationResult> {
    return (await api.post(`${base}/batches/${id}/cancel`, input, scope(supplierId))).data
  },
}

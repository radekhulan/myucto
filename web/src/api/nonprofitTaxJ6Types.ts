export type J6ProfileKind = 'narrow_vpp' | 'broad_vpp' | 'non_vpp' | 'svj' | 'documented'
export type J6PoolKind = 'revenue' | 'expense'
export type J6PreviewKind = 'decision' | 'result' | 'coverage' | 'usage' | 'funding_reference' | 'funding_history'
export type J6FrozenKind = 'decision' | 'result' | 'coverage' | 'usage'

export interface J6Profile { kind: J6ProfileKind; evidence: string; entity_kind?: string }
export interface J6Method { mode: 'documented_manual'; valid_from: string; valid_to: string; legal_basis: string; evidence: string }
export interface J6DraftHeader { profile: J6Profile; method?: J6Method }
export interface J6Draft {
  id: number
  supplier_id: number
  period_id: number
  revision_no: number
  source_manifest_id: number
  source_input_hash: string
  header: J6DraftHeader
  created_by: number
  created_at: string
  updated_at: string
}
export interface J6Capabilities { can_edit: boolean; can_preview: boolean; can_confirm: boolean; source_complete: boolean; reason_code: string | null }
export interface J6DraftEnvelope { draft: J6Draft; capabilities: J6Capabilities }
export interface J6Period { id: number; fiscal_year: number; starts_on: string; ends_on: string }
export interface J6SnapshotHeader {
  schema: 'nonprofit-j6-source.v2'
  scope: 'current-period-source'
  supplier_id: number
  period_id: number
  period: J6Period
  frameworks: unknown[]
  ruleset: string
  funding_history_mode: 'paged_assessment_graph_v1'
  source_fact_schemas: Record<string, string>
}
export interface J6CurrentSourceHeader { header: J6SnapshotHeader; input_hash: string; root_hash: string; row_count: number; finding_count: number; chunk_count: number }

export interface J6SourceRow {
  id: string
  line_id: number
  entry_id: number
  line_no: number
  account_id: number
  account_code: string
  date: string
  kind: 'revenue' | 'expense'
  amount_cents: number
  activity: string | null
  activity_kind_id: number | null
  tax_regime: string | null
  mandatory_non_deductible: boolean
  neutral: boolean
  axes: Record<string, { name?: string; code?: string; value_id?: number }> | []
  provenance: Record<string, unknown>
  origin: { line_id: number; type: string; id: number | null }
  source_type: string
  source_id: number | null
  allocation: Record<string, unknown> | null
  module_facts: Record<string, unknown> | unknown[]
}
export type J6FindingCode = 'missing_activity' | 'missing_activity_kind' | 'missing_tax_regime' | 'invalid_activity' | 'invalid_tax_regime' | 'unknown_activity_origin' | 'unknown_activity_kind_origin' | 'unknown_tax_regime_origin' | 'joint_allocation_required' | 'joint_dimensions_changed'
export interface J6SourceFinding { line_id: number; code: J6FindingCode }
export interface J6DraftSourcePage { draft: J6Draft; rows: J6SourceRow[]; findings: J6SourceFinding[]; after: string | null }

export interface J6TaxDecision {
  final_regime?: 'subject' | 'exempt' | 'out_of_scope' | ''
  final_method?: 'return' | 'none' | 'final_withholding' | ''
  title: string
  evidence: string
  legal_basis?: string
  deductible_cents?: number
  cohort?: string
  facts?: Record<string, string | number | boolean>
}
export interface J6TaxActivity { evidence: string; cohorts?: Record<string, string> }
export interface J6TaxPool { evidence: string; prior_period_review?: { evidence: string; legal_basis: string; adjustment_cents: number } }
export interface J6TaxFundingLink { amount_cents: number; evidence: string; deductible_funded_cents?: number }
export interface J6DecisionRow { source_id: string; payload: J6TaxDecision; record_hash: string }
export interface J6ActivityRow { activity_kind_id: string; payload: J6TaxActivity; record_hash: string }
export interface J6PoolRow { pool_kind: J6PoolKind; anchor: string; payload: J6TaxPool; record_hash: string }
export interface J6LinkRow { source_id: string; cost_id: string; payload: J6TaxFundingLink; record_hash: string }
export interface J6MemberRow { member_id: string }
export interface J6DraftCollectionPage<T> { draft: J6Draft; rows: T[]; after: string | null }

export interface J6PatchRequest<TUpsert, TDelete> { expected_revision: number; upserts: TUpsert[]; deletes: TDelete[] }
export interface J6PreviewReceipt {
  header: Record<string, unknown>
  root_hashes: Record<J6FrozenKind, string>
  counts: Record<J6FrozenKind, number>
  evidence_root_hashes: Record<'funding_reference' | 'funding_history', string>
  evidence_counts: Record<'funding_reference' | 'funding_history', number>
  summary: Record<string, number | string | boolean | null>
  preview_token: string
  expires_in: 900
}
export interface J6PreviewPage { kind: J6PreviewKind; rows: Record<string, unknown>[]; next_page: number | null }
export type J6AssessmentStatus = 'confirmed' | 'withdrawn'
export type J6StorageVersion = 'paged_v2' | 'legacy_json_v1' | 'legacy_json_v2'
export interface J6BridgeSummary {
  accounting_result_cents: number
  excluded_revenue_cents: number
  cost_addbacks_cents: number
  assessed_result_cents: number
  source_row_count: number
  [key: string]: number | string | boolean | null
}
export interface J6AssessmentSummary {
  id: number
  supplier_id: number
  period_id: number
  revision_no: number
  previous_id: number | null
  status: J6AssessmentStatus
  reason: string
  ruleset: string
  storage_version: J6StorageVersion
  created_by: number
  created_at: string
  summary: J6BridgeSummary | null
  source_input_hash: string | null
  snapshot_input_hash: string | null
  receipt_hash: string | null
  conversion_required?: boolean
}
export interface J6HistoryHead { assessment_id: number | null; revision_no: number; status: J6AssessmentStatus | null }
export interface J6AssessmentHistoryPage { head: J6HistoryHead; rows: J6AssessmentSummary[]; after: string | null }
export interface J6PeriodSummary { source: J6CurrentSourceHeader; latest: J6AssessmentSummary | null }
export interface J6CurrentDraft { draft: J6Draft | null; capabilities: J6Capabilities | null }
export interface J6AssessmentCompletion { assessment_id: number; supplier_id: number; period_id: number; revision: number; status: J6AssessmentStatus; request_hash: string; receipt: Record<string, unknown> }
export interface J6WithdrawRequest { reason: string; idempotency_key: string }
export interface J6ConvertRequest { expected_storage_version: J6StorageVersion; expected_input_hash: string; reason: string }
export type J6DocumentFormat = 'pdf' | 'xlsx'
export interface J6FrozenSourcePage { rows: J6SourceRow[]; findings: J6SourceFinding[]; after: string | null }
export interface J6FrozenCalculationPage { kind: J6FrozenKind; rows: Record<string, unknown>[]; after: string | null }

export interface J6DraftOpenRequest { profile: J6Profile; method?: J6Method }
export interface J6DraftResetRequest extends J6DraftOpenRequest { expected_revision: number }
export interface J6ConfirmRequest { expected_revision: number; expected_assessment_id: number | null; reason: string; idempotency_key: string }

import { api } from './client'

export interface TaxSource {
  id: string
  line_id: number
  account_code: string
  date: string
  kind: 'revenue' | 'expense'
  amount_cents: number
  activity_kind_id: number | null
  activity: string | null
  tax_regime: string | null
  mandatory_non_deductible: boolean
  axes: Record<string, { name: string; code: string; value_id: number }> | []
  origin: { line_id: number; type: string; id: number | null }
  svj_owner_facts?: { owner: 'property_service_lifecycle' | 'property_funding'; kind: 'revenue' | 'expense'; legal_basis: string; case_id?: number; operation_id?: number; covered_cost_cents?: number }
}
export interface TaxDecision {
  final_regime?: 'subject' | 'exempt' | 'out_of_scope' | ''
  final_method?: 'return' | 'none' | 'final_withholding' | ''
  title: string
  evidence: string
  legal_basis?: string
  deductible_cents?: number
  cohort?: string
  facts?: Record<string, string | number | boolean>
}
export interface TaxPool {
  anchor: string
  members: string[]
  evidence: string
  prior_period_review?: { evidence: string; legal_basis: string; adjustment_cents: number }
}
export interface TaxAssessment {
  method?: { mode: 'documented_manual'; valid_from: string; valid_to: string; legal_basis: string; evidence: string }
  profile: { kind: string; evidence: string; entity_kind?: string }
  activities: Record<string, { evidence: string; cohorts?: Record<string, string> }>
  decisions: Record<string, TaxDecision>
  funding: { source_id: string; cost_id: string; amount_cents: number; evidence: string; deductible_funded_cents?: number }[]
  funding_sources?: TaxPool[]
  funding_costs?: TaxPool[]
}
export interface AssessedRow extends TaxSource {
  decision: TaxDecision
  classification: { regime: string; method: string; legal_basis: string }
  deductible_cents: number
  adjustment_cents: number
  reference_current?: boolean
}
export interface TaxResult {
  assessment_method?: 'ruleset' | 'documented_manual'
  accounting_result_cents: number
  excluded_revenue_cents: number
  cost_addbacks_cents: number
  assessed_result_cents: number
  scope: 'classification_bridge_before_general_adjustments'
  dppo_supported: false
  rows: AssessedRow[]
  activities: { activity_kind_id: string; cohort: string; regime: string; revenue_cents: number; cost_cents: number; contribution_cents: number }[]
  funding_usage: { anchor: string; members: string[]; net_source_cents: number; prior_drawdown_cents: number; drawdown_cents: number; remaining_cents: number }[]
  external_adjustments: { funding_anchor: string; evidence: string; legal_basis: string; adjustment_cents: number; included_in_bridge: false }[]
}
export interface TaxSnapshot {
  legal_profile?: { id: number; revision_no: number; record_hash: string; profile: { kind: 'narrow_vpp' | 'broad_vpp' | 'non_vpp' | 'svj'; entity_type: string; reduction_band: string } }
  svj_module_proof?: { id: number; revision_no: number }[]
  assessment_support?: { automatic: boolean; documented_manual: boolean; automatic_from: string; automatic_to: string }
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string }
  input_hash: string
  sources: TaxSource[]
  findings: { line_id?: number; code: string }[]
  accounting_result_cents: number
  funding_reference_sources: Record<string, AssessedRow> | []
}
export interface TaxRevision {
  id: number
  revision_no: number
  status: 'confirmed' | 'withdrawn'
  reason: string
  created_at: string
  created_by: number
  input_hash: string
  snapshot: TaxSnapshot
  assessment: TaxAssessment
  result: TaxResult
}
export interface TaxPreview { snapshot: TaxSnapshot; result: TaxResult; previous_assessment_id: number | null }
export interface TaxExport {
  revision: TaxRevision
  current_confirmed: boolean
  dppo_supported: false
  current_check: { complete: boolean; findings: { code: string; message?: string }[] }
}
const period = (id: number) => `/accounting/periods/${id}/nonprofit-tax`
const revision = (id: number) => `/accounting/nonprofit-tax-assessments/${id}`
export const nonprofitTaxApi = {
  read: async (id: number): Promise<{ snapshot: TaxSnapshot; latest: TaxRevision | null }> => (await api.get(period(id))).data,
  history: async (id: number): Promise<TaxRevision[]> => (await api.get(`${period(id)}/history`)).data,
  preview: async (id: number, assessment: TaxAssessment): Promise<TaxPreview> => (await api.post(`${period(id)}/preview`, { assessment })).data,
  confirm: async (id: number, body: { assessment: TaxAssessment; input_hash: string; reason: string; idempotency_key: string; expected_assessment_id: number | null }): Promise<TaxRevision> => (await api.post(`${period(id)}/confirm`, body)).data,
  withdraw: async (id: number, body: { expected_id: number; reason: string; idempotency_key: string }): Promise<TaxRevision> => (await api.post(`${period(id)}/withdraw`, body)).data,
  export: async (id: number): Promise<TaxExport> => (await api.get(`${revision(id)}/export`)).data,
  document: (id: number, format: 'pdf' | 'xlsx') => api.get(`${revision(id)}/export.${format}`, { responseType: 'blob' }),
}

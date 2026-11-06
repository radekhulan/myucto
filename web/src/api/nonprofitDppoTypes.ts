import type { AccountingPeriod } from './accounting'
import type { TaxReturnState } from './taxReturn'
export type LegalKind = 'narrow_vpp' | 'broad_vpp' | 'non_vpp' | 'svj'
export interface LegalProfile { kind: LegalKind; entity_type: string; main_activity_nonbusiness: boolean; evidence: string; legal_basis: string; reduction_band?: string; ruleset?: string }
export interface LegalRevision { id: number; period_id: number; revision_no: number; previous_id: number | null; profile: LegalProfile; record_hash: string; reason: string; created_by: number }
export type NonprofitOperationKey = 'compute' | 'finalize' | 'export_xml' | 'submit'
export interface NonprofitOperationCapability { allowed: boolean; code: string | null; message: string | null }
export interface NonprofitFormManifest { form_code: string; form_version: string; form_date: string; xsd_sha256: string; xsd_verified: boolean; official_source: string }
export interface LegalCapabilities {
  can_confirm: boolean
  session_only: true
  native_dppo: boolean
  calculation_ready?: boolean | null
  readiness_checked?: boolean
  calculation_accepted?: boolean
  filing_ready: boolean
  can_edit_reduction?: boolean
  operations?: Partial<Record<NonprofitOperationKey, NonprofitOperationCapability>>
  form_manifest?: NonprofitFormManifest | null
  preparation_findings: Array<{ code: string; message: string }>
}
export interface LegalOverview { period: AccountingPeriod; latest: LegalRevision | null; entity_types: string[]; kinds: LegalKind[]; capabilities: LegalCapabilities }
export interface LegalReadiness { period: AccountingPeriod; capabilities: LegalCapabilities }
export interface AcceptanceRevision {
  id: number
  revision_no: number
  previous_id: number | null
  status: 'accepted' | 'revoked'
  ruleset: string
  form_code: string
  form_version: string
  xsd_sha256: string
  verification_reference: string
  reason: string
  created_by: number
  created_at: string
}
export interface AcceptanceState {
  accepted: boolean
  stale: boolean
  head: AcceptanceRevision | null
  binding: { ruleset: string; form_code: string; form_version: string; xsd_sha256: string }
  history: AcceptanceRevision[]
  can_change: boolean
}
export interface AcceptanceChange { confirmed: boolean; verification_reference: string; reason: string; idempotency_key: string; expected_head_id: number | null }
export interface LegalConfirm { expected_revision: number; profile: LegalProfile; reason: string; idempotency_key: string }
export interface SavingCost { cost_line_id: number; period_id: number; date: string; account_code: string; amount_cents: string; available_cents: string; allowed_purposes: string[]; assessment_id: number; description?: string; document_ref?: string | null }
export interface SavingEvent { id: number; operation: 'use' | 'reverse_use' | 'void' | 'restore'; period_id: number | null; cost_line_id: number | null; amount_cents: string; original_id: number | null; record_hash: string }
export interface SavingSource { source: { id: number; period_id: number; snapshot_id: number; reduction_kc: number; saving_cents: string; basis: { period: { id: number; starts_on: string; ends_on: string }; legal_profile: { profile: Pick<LegalProfile, 'kind' | 'entity_type' | 'reduction_band'> } }; record_hash: string }; events: SavingEvent[]; remaining_cents: string; void: boolean; head_hash: string; next_period: AccountingPeriod | null; eligibility: { ready: boolean; code: string | null; message: string | null }; eligible_costs: SavingCost[]; cost_findings?: Array<{ cost_line_id: number; code: string; message: string }> }
export interface SavingOverview { sources: SavingSource[]; capabilities: { can_confirm: boolean; session_only: true } }
export interface SavingUseInput { cost_line_id: number; amount_cents: string; purpose: string; evidence: string }
export interface SavingPlan { operation: 'use' | 'reverse_use'; source_id: number; source_hash: string; head_hash: string; period_id: number; cost_line_id: number; amount_cents: string; preview_hash: string; proof: Record<string, unknown> }
export interface SavingConfirm { input: SavingUseInput; preview_hash: string; expected_head_hash: string; idempotency_key: string }
export interface SavingReverseConfirm { reason: string; preview_hash: string; expected_head_hash: string; idempotency_key: string }
export interface NativeNonprofitResult { filing_obligation: { required: boolean; legal_basis: string; taxable_sources: unknown[]; unused_reduction_addback_kc: number }; saving: { base_before_reduction_kc: number; reduction_kc: number; credits_entitlement_kc: number; tax_without_reduction_kc: number; tax_with_reduction_kc: number; saving_kc: number }; proof_hash: string }
export type NonprofitDppoState = Omit<TaxReturnState, 'computed'> & { computed: TaxReturnState['computed'] & { nonprofit?: NativeNonprofitResult }; nonprofit_filing_proof_hash?: string }

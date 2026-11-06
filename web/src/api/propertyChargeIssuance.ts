import { api } from './client'
import type { Fraction, PropertyObject, PropertyRevision } from './propertyRegistry'
import type { ChargeVersion, ChargeComponent } from './propertyCharges'

export interface CaseIdentity { schema: 1; case_ref: string; occurrence_ref: string; title_ref: string; decision_ref: string; decided_on: string; evidence_ref: string }
export interface CaseDistinction { against_source_keys: string[]; legal_reason: string; evidence_ref: string }
export interface CaseFamily { supplier_id: number; unit_id: number; source_key: string; root_charge_id: number; latest_charge_id: number; latest_revision: number; status: 'draft' | 'posted' | 'reversed' | 'cancelled'; row_version: number; case_identity: CaseIdentity | null; case_distinction: CaseDistinction | null; identity_status: 'explicit' | 'legacy_unclassified' }
export interface CaseCandidate extends CaseFamily { reasons: ('period_overlap' | 'same_decision_occurrence')[] }
export interface LiabilityGroup { group_key: string; role: string; mode: string; basis: string; members: number[]; extent: Fraction; evidence_ref: string; legal_reason: string; valid_from: string; valid_to: string | null }
export interface DeliveryProof { recipient_id: number; represented_ids: number[]; authority_ref: string; delivered_at: string; evidence_ref: string }
export interface ServiceNotice { mode: string; title_ref: string; written_notice_ref?: string; reason?: string; deliveries?: DeliveryProof[]; agreement?: { effective_at: string; recipient_ids: number[]; evidence_ref: string; legal_basis: string } | null }
export interface IssueUnit { unit_id: number; expected_revision: number; previous_charge_id: number | null; liability_groups: LiabilityGroup[]; notices: { component_id: number; notice: ServiceNotice }[]; case_distinction?: CaseDistinction }
export interface IssueAccount { component_id: number; receivable_account: string; counter_account: string; basis_ref: string; settlement_due_on: string | null; dimensions: Record<number, number> }
export interface IssueRequest { case_identity?: CaseIdentity; version_id: number; source_key: string; title: string; issue_mode: string; period_from: string; period_to: string; claim_origin_at: string; issued_at: string; accounting_date: string; due_date: string; reason: string; units: IssueUnit[]; accounts: IssueAccount[] }
export interface IssueLine { component_id: number; component: ChargeComponent; service_id: number | null; amount_cents: string; target_cents: string; account: IssueAccount; dimensions: Record<number, number> }
export interface IssueSnapshot { unit_id: number; revision_no: number; previous_charge_id: number | null; source_key: string; title: string; issue_mode: string; period_from: string; period_to: string; claim_origin_at: string; issued_at: string; accounting_date: string; due_date: string; amount_cents: string; frequency: string; version_id: number; registry: { object: PropertyObject; revision: PropertyRevision }; liability_groups: LiabilityGroup[]; parties: IssueParty[]; lines: IssueLine[]; notices: IssueUnit['notices']; case_identity?: CaseIdentity; case_distinction?: CaseDistinction; case_candidates?: CaseCandidate[] }
export interface IssuedCharge { id: number; batch_id: number; unit_id: number; source_key: string; revision_no: number; status: 'draft' | 'posted' | 'reversed' | 'cancelled'; row_version: number; period_from: string; period_to: string; due_date: string; amount_cents: string; snapshot_hash: string; journal_entry_id: number | null; reversal_entry_id: number | null; snapshot: IssueSnapshot }
export interface IssuePreview { snapshot: { request: IssueRequest; charges: IssueSnapshot[]; total_cents: string; allocation_regime: string; security_basis: string }; preview_hash: string }
export interface IssueBatch { id: number; request: IssueRequest; charges: IssuedCharge[]; preview_hash: string }
export interface IssueParty { id: number; company_name: string; first_name: string | null; last_name: string | null }
export interface IssueContext { as_of: string; version: ChargeVersion; units: PropertyObject[]; parties: IssueParty[]; accounts: { account_code: string; name: string; account_type: string }[]; dimensions: { id: number; name: string; system_key: string | null; values: { id: number; name: string }[] }[]; history: IssuedCharge[]; case_families?: CaseFamily[] }
export interface IssueEvidence { id: number; charge_id: number; request: { kind: string; effective_at: string; reason: string; evidence_ref: string; facts: Record<string, unknown>; expected_event_id: number }; recorded_at: string }
export interface ReversalRequest { row_version: number; accounting_date: string; effective_at: string; reason: string; evidence_ref: string; idempotency_key: string }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
const path = '/property/charges/issues'
export const propertyChargeIssuanceApi = {
  list: (supplier: number) => api.get<{ items: IssuedCharge[]; capabilities: { issue: boolean; post: boolean; reverse: boolean } }>(path, options(supplier)).then(r => r.data),
  context: (supplier: number, version: number, at: string) => api.get<IssueContext>(`${path}/context`, { ...options(supplier), params: { version_id: version, as_of: at } }).then(r => r.data),
  detail: (supplier: number, charge: number) => api.get<{ charge: IssuedCharge; evidence: IssueEvidence[] }>(`${path}/${charge}`, options(supplier)).then(r => r.data),
  batch: (supplier: number, batch: number) => api.get<IssueBatch>(`${path}/batches/${batch}`, options(supplier)).then(r => r.data),
  preview: (supplier: number, request: IssueRequest) => api.post<IssuePreview>(`${path}/preview`, request, options(supplier)).then(r => r.data),
  issue: (supplier: number, preview: IssuePreview, token: string) => api.post<IssueBatch>(path, { request: preview.snapshot.request, preview_hash: preview.preview_hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  post: (supplier: number, batch: IssueBatch) => api.post<IssueBatch>(`${path}/batches/${batch.id}/post`, { expected: batch.charges.map(c => ({ id: c.id, row_version: c.row_version })) }, options(supplier)).then(r => r.data),
  reverse: (supplier: number, charge: number, request: ReversalRequest) => api.post<IssuedCharge>(`${path}/${charge}/reverse`, request, options(supplier)).then(r => r.data),
  evidence: (supplier: number, charge: number, request: IssueEvidence['request'], token: string) => api.post<IssueEvidence>(`${path}/${charge}/evidence`, { request, idempotency_key: token }, options(supplier)).then(r => r.data),
}

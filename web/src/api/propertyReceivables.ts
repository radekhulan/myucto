import { api } from './client'
import type { PropertyObject } from './propertyRegistry'
import type { CardTotals, CardPrecision } from './propertyPaymentOperations'
export type Amount = string | null
export interface SaldoItem { unit_id: number; unit_code: string; totals: CardTotals & { accessory_receivable_cents: Amount; principal_receivable_cents: Amount }; aging: Record<'not_due' | '1_30' | '31_90' | '91_plus', Amount>; claim_count: number; credit_count: number; precision: CardPrecision; uncertain_event_count: number; liability_review_count: number; coverage: { financial_complete: boolean; complete: false; unresolved_negative_charge_line_count: number } }
export interface SaldoPage { kind: 'recorded_property_saldo_page'; as_of: string; known_at: string; items: SaldoItem[]; next_cursor: string | null; read_scope_digest: string; proof_scope: { kind: 'selected_unit_financial_closure'; unit_ids: number[]; schema: 1 }; consistency: 'request_snapshot'; coverage: { scope: 'recorded_s3_s4'; complete: false } }
export interface LiabilityGroup { group_key: string; role: 'debtor' | 'guarantor'; mode: 'individual' | 'solidary'; basis: 'individual_title' | 'sjm_713' | 'joint_act_1127' | 'documented_title'; members: number[]; extent: { num: string; den: string }; evidence_ref: string; legal_reason: string; valid_from: string; valid_to: string | null; remaining_cap_cents?: Amount }
export interface LegalClaim { claim_key: string; debt_id: number | null; title_ref: string; due_date: string; amount_cents: Amount; groups: LiabilityGroup[]; precision: CardPrecision; complete: boolean; liability: { head_event_id: number | null; event_id: number | null; complete: boolean; review_required: boolean } }
export interface LegalProjection { as_of: string; known_at: string; claims: LegalClaim[]; totals: CardTotals; precision: CardPrecision; coverage: { complete: false; financial_complete: boolean } }
export interface PartySaldoItem { party_ids: number[]; parties: Array<{ id: number; label: string }>; unit_ids: number[]; group_keys: string[]; receivable_cents: string; credit_cents: string; liability_review_required: boolean }
export interface PartySaldoPage { accounting_date: string; knowledge_basis: 'current_ledger'; items: PartySaldoItem[]; next_cursor: string | null; totals: { receivable: string; credit: string } | null }
export interface DirectoryParty { id: number; company_name: string; first_name: string | null; last_name: string | null }
export interface TransferRequest { unit_id: number; registry_revision_id: number; effective_at: string; decision: 'transfer_confirmed' | 'transfer_not_applicable' | 'review_required' | 'superseding_correction'; reason: string; acquisition: { kind: string; applicable_law: string; legal_basis: string; evidence: string; had_and_could_discover: boolean; knowledge_evidence: string; guarantee_evidence: string }; debts: Array<{ debt_id: number; expected_event_id: number | null; groups: LiabilityGroup[] }> }
export interface TransferSnapshot { request: TransferRequest; parties: DirectoryParty[]; debts: Array<{ debt_id: number; remaining_cents: Amount; groups: LiabilityGroup[]; previous_liability: { groups: LiabilityGroup[] } }> }
export interface PartyEvent { id: number; effective_at: string; recorded_at: string; snapshot_hash: string; snapshot: TransferSnapshot }
export interface CertificateRequest { unit_id: number; as_of: string; known_at: string; requester_id: number; authority_evidence: string; purpose: string; coverage_acknowledgement: string }
export interface CertificateSnapshot { request: CertificateRequest; projection: LegalProjection; registry: PropertyObject; requester: DirectoryParty; statement_scope: 'recorded_s3_s4'; unconditional_no_debt: false }
export interface Certificate { id: number; recorded_at: string; snapshot_hash: string; snapshot: CertificateSnapshot }
export interface Preview<T> { preview_hash: string; snapshot: T }
export interface LegalContext { as_of: string; known_at: string; unit: PropertyObject; projection: LegalProjection; events: PartyEvent[]; parties: DirectoryParty[]; party_labels_scope: 'current_directory'; certificates: Array<{ id: number; recorded_at: string; as_of: string; known_at: string; snapshot_hash: string }>; capabilities: { manage: boolean; confirm: boolean; certificates: boolean } }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
const base = '/property/receivables'
export const propertyReceivablesApi = {
  saldo: (supplier: number, query: { as_of: string; known_at: string; limit: number; cursor?: string }) => api.get<SaldoPage>('/property/catalog/payments/saldo', { ...options(supplier), params: query }).then(r => r.data),
  partySaldo: (supplier: number, query: { date: string; limit: number; after?: string }) => api.get<PartySaldoPage>('/property/catalog/payments/reconciliation/parties', { ...options(supplier), params: query }).then(r => r.data),
  context: (supplier: number, query: { unit_id: number; as_of: string; known_at: string }) => api.get<LegalContext>(`${base}/context`, { ...options(supplier), params: query }).then(r => r.data),
  transferPreview: (supplier: number, request: TransferRequest) => api.post<Preview<TransferSnapshot>>(`${base}/transfers/preview`, request, options(supplier)).then(r => r.data),
  transferConfirm: (supplier: number, request: TransferRequest, preview_hash: string, idempotency_key: string) => api.post<PartyEvent>(`${base}/transfers/confirm`, { request, preview_hash, idempotency_key }, options(supplier)).then(r => r.data),
  certificatePreview: (supplier: number, request: CertificateRequest) => api.post<Preview<CertificateSnapshot>>(`${base}/certificates/preview`, request, options(supplier)).then(r => r.data),
  certificateIssue: (supplier: number, request: CertificateRequest, preview_hash: string, idempotency_key: string) => api.post<Certificate>(`${base}/certificates/issue`, { request, preview_hash, idempotency_key }, options(supplier)).then(r => r.data),
  certificate: (supplier: number, id: number) => api.get<Certificate>(`${base}/certificates/${id}`, options(supplier)).then(r => r.data),
}

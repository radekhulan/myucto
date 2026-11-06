import { api } from './client'
import type { PropertyObject } from './propertyRegistry'
export interface PaymentCandidate { kind: 'bank' | 'cash'; id: number; date: string; amount: string; currency: string; direction: 'in' | 'out'; partner_name: string | null; description: string | null; variable_symbol: string | null; specific_symbol?: string | null; existing_owner: boolean; available_cents: string; native_amount_minor?: string | null; available_native_minor?: string | null; support: 'preview_required' | 'fx_incomplete' }
export type PaymentCandidateKind = 'bank' | 'cash'
export interface PaymentCandidatePage { items: PaymentCandidate[]; next_cursor: number | null; coverage: { eligibility: 'current_preview_required'; fx: 'incomplete' } }
export interface ClearingCell { key: string; amount_cents: string; dimensions: Record<number, number> }
export interface NativePaymentValue { currency: 'EUR'; amount: string; amount_minor: string; minor_unit_decimals: 2; fx_rate: string }
export interface PaymentSourceDetails { source_id: number | null; source_version: number; revision_id: number | null; revision_date: string | null; kind: 'bank' | 'cash'; id: number; date: string; direction: 'in' | 'out'; amount_cents: string; consumed_cents: string; available_cents: string; currency?: string; native?: NativePaymentValue | null; consumed_native_minor?: string | null; available_native_minor?: string | null; cells: Array<ClearingCell & { available_cents: string; native_amount_minor?: string | null; consumed_native_minor?: string | null; available_native_minor?: string | null; dimension_splits: Record<number, Record<number, string>> }> }
export interface ClearingRevisionRequest { source_id: number; expected_source_version: number; expected_revision_id: number | null; accounting_date: string; effective_at: string; reason: string; evidence: string; moves: Array<{ from_key: string; amount_cents: string; to: { key: string; dimensions: Record<number, number> } }> }
export interface ClearingRevisionPreview { status: 'ready'; preview_hash: string; snapshot: { plan: { cells: Array<{ key: string; amount_cents: string; line: { dimensions: Record<number, number> } }> } } }
export interface CashSourceDraft { register_id: number; doc_type: 'in' | 'out'; issue_date: string; partner_name: string; description: string; total_amount: string }
export type PaymentWorkflowSource = { kind: 'bank' | 'cash'; id: number } | { kind: 'cash'; draft: CashSourceDraft }
export type CreditThirdPartyBasis = 'assignment' | 'inheritance' | 'mistaken_payment' | 'settlement_title' | 'other_documented'
export interface PaymentCreditEntitlement { unit_id: number; party_ids: number[]; evidence: string; due_date: string; third_party?: { basis: CreditThirdPartyBasis; evidence: string } }
export type PaymentComponent = 'determined_cost' | 'default_interest' | 'interest' | 'principal'
export interface PaymentThirdParty { basis: 'debtor_consent' | 'guarantor' | 'security_provider'; evidence: string }
export interface PaymentSecurityFact { kind: 'secured' | 'unsecured'; evidence: string }
export interface PaymentReminder { debt_id: number; evidence: string; recipient_ids: number[]; delivered_at: string }
export interface PaymentFacts {
  payer_id: number; debtor_id: number; received_at: string; third_party?: PaymentThirdParty
  choice?: { claim_ids: number[]; evidence: string }
  security_comparison?: { tiers: number[][]; facts: Record<number, PaymentSecurityFact>; evidence: string }
  reminders?: PaymentReminder[]
  principal_distribution?: Record<number, { lines: Array<{ line_id: number; amount_cents: string }>; evidence: string }>
  accessory_distribution?: Record<number, Partial<Record<PaymentComponent, { parts: Array<{ id: number; amount_cents: string }>; evidence: string }>>>
}
export interface PaymentAllocationPart { kind: 'allocation'; amount_cents: string; accounting_date: string; reason: string; debts: number[]; payment?: PaymentFacts; credit_entitlement?: PaymentCreditEntitlement; clearing_selection?: Array<{ key: string; amount_cents: string }> }
export interface PaymentCashReceipt { cash_document_id: number | null; recipient_id: number; recipient_name: string; evidence: string }
export interface PaymentRefundPart { kind: 'refund'; amount_cents: string; accounting_date: string; reason: string; reservation_id: number; expected_reservation_version: number; cash_receipt?: PaymentCashReceipt; clearing_selection?: Array<{ key: string; amount_cents: string }> }
export type PaymentPartRequest = PaymentAllocationPart | PaymentRefundPart
export interface PaymentWorkflowRequest { source: PaymentWorkflowSource; clearing_cells: ClearingCell[]; parts: Array<{ key: string; request: PaymentPartRequest }> }
export interface PaymentWorkflowPreview { status: 'ready'; preview_hash: string; source_etag: string; source: { kind: string; id: number | null; date: string; amount_cents: string; currency: string }; snapshot: { settled_cents: string; available_cents: string; cells: ClearingCell[]; parts: Array<{ key: string; request: PaymentPartRequest }> } }
export interface PaymentWorkflowResult { id: number; status: string; source: { kind: string; id: number }; settled_cents: string; available_cents: string; journal_entry_id: number; parts: Array<{ part_key: string; operation_id: number }> }
export interface PaymentWorkflowHistory { id: number; kind: string; source_id: number; date: string; amount_cents: string; settled_cents: string; available_cents: string; recorded_at: string }
export interface PaymentWorkflowHistoryPage { items: PaymentWorkflowHistory[]; next_cursor: number | null }
export interface PaymentWorkflowContext { as_of: string; candidates: PaymentCandidate[]; candidate_coverage: { limit_per_kind: number; truncated: boolean; eligibility: string; fx: 'incomplete' }; registers: Array<{ id: number; name: string; currency_code: string }>; units: PropertyObject[]; dimensions: Array<{ type_id: number; type_name: string; system_key: string | null; id: number; name: string; code: string }>; capabilities: { manage: boolean; post: boolean; reverse: boolean; bank: boolean; cash: boolean } }
export type BankProposalStatus = 'identified' | 'review_required' | 'unidentified'
export interface BankProposalSignal { signal: 'vs' | 'sipo_link' | 'account' | 'ss' | 'payer_account' | 'text'; identifier_kind?: string; client_id?: number }
export interface BankProposalCandidate { unit_id: number; unit_no: string; score: number; signals: BankProposalSignal[]; debtor_ids: number[]; ownership_review_required: boolean }
export interface BankProposalResolution { bank_transaction_id: number; resolver_version: number; received_at: string; status: BankProposalStatus; score: number; unit_id: number | null; debtor_id: number | null; payer_id: number | null; reasons: string[]; candidates: BankProposalCandidate[] }
export interface BankProposalLink { source?: { id: number; amount_cents: string; consumed_cents: string }; workflow_id?: number; proposal?: { status: BankProposalStatus; unit_id: number | null; score: number } }
export interface BankProposalQueueItem { bank_transaction_id: number; status: BankProposalStatus; unit_id: number | null; score: number; row_version: number; updated_at: string; resolution: BankProposalResolution; transaction: { date: string; amount: string; variable_symbol: string | null; specific_symbol: string | null; partner_name: string | null; description: string | null } }
export interface BankProposalPage { items: BankProposalQueueItem[]; next_cursor: number | null }
export interface BankProposalReview { code: string; details?: Record<string, unknown>; amount_cents?: string }
export type BankProposalPlan =
  | { status: 'used'; bank_transaction_id: number; link: BankProposalLink }
  | { status: 'proposal'; bank_transaction_id: number; resolution: BankProposalResolution; request: PaymentWorkflowRequest | null; preview: PaymentWorkflowPreview | null; review: BankProposalReview | null; remaining_cents?: string }
export interface BankProposalOverrides { unit_id?: number; debtor_id?: number; payer_id?: number }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const propertyPaymentsApi = {
  source: (supplier: number, kind: 'bank' | 'cash', id: number) => api.get<PaymentSourceDetails>('/property/payments/source', { ...options(supplier), params: { kind, id } }).then(r => r.data),
  revisionPreview: (supplier: number, request: ClearingRevisionRequest) => api.post<ClearingRevisionPreview>('/property/payments/source-revisions/preview', request, options(supplier)).then(r => r.data),
  revisionConfirm: (supplier: number, request: ClearingRevisionRequest, hash: string, token: string) => api.post<{ id: number }>('/property/payments/source-revisions/confirm', { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  context: (supplier: number, asOf: string) => api.get<PaymentWorkflowContext>('/property/payments/context', { ...options(supplier), params: { as_of: asOf } }).then(r => r.data),
  candidates: (supplier: number, kind: PaymentCandidateKind, afterId: number, limit = 100, filter: { q?: string; id?: number } = {}) => api.get<PaymentCandidatePage>('/property/payments/candidates', { ...options(supplier), params: { kind, after_id: afterId, limit, q: filter.q || undefined, id: filter.id } }).then(r => r.data),
  history: (supplier: number, beforeId = 0, limit = 25) => api.get<PaymentWorkflowHistoryPage>('/property/payments/history', { ...options(supplier), params: { before_id: beforeId, limit } }).then(r => r.data),
  preview: (supplier: number, request: PaymentWorkflowRequest) => api.post<PaymentWorkflowPreview>('/property/payments/preview', request, options(supplier)).then(r => r.data),
  confirm: (supplier: number, request: PaymentWorkflowRequest, hash: string, token: string) => api.post<PaymentWorkflowResult>('/property/payments/confirm', { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  bankProposals: (supplier: number, afterId = 0, limit = 25, status?: BankProposalStatus) => api.get<BankProposalPage>('/property/payments/bank-proposals', { ...options(supplier), params: { after_id: afterId, limit, status } }).then(r => r.data),
  bankProposalPlan: (supplier: number, transactionId: number, overrides: BankProposalOverrides = {}) => api.post<BankProposalPlan>(`/property/payments/bank-proposals/${transactionId}/plan`, overrides, options(supplier)).then(r => r.data),
  bankLinks: (supplier: number, ids: number[]) => api.get<{ enabled: boolean; items: Record<string, BankProposalLink> }>('/property/payments/bank-links', { ...options(supplier), params: { ids: ids.join(',') } }).then(r => r.data),
}

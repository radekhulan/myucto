import { api } from './client'
import type { DimensionOverview } from './dimensions'
import { useSupplierStore } from '@/stores/supplier'
import { requestSupplierId } from '@/workspace/supplierContext'

export type MemberStatus = 'active' | 'suspended' | 'ended'
export type MembershipOperation = 'issue' | 'reverse'
export type SettlementStatus = 'reversed' | 'ambiguous' | 'unreconciled' | 'settled' | 'partially_settled'

export interface MembershipLegalBasis { organisation_form: 'association'; relationship: 'nonreciprocal_membership'; reference: string; evidence: string }

/** Řádek seznamu členů: poslední revize může začínat v budoucnu, není to dnešní stav. */
export interface MemberRow {
  contribution?: {status: 'none' | 'paid' | 'unpaid' | 'unproven'; unpaired_count?: number; claim_count: number; prescribed_cents: string; paid_cents: string | null; open_cents: string | null}
  id: number; supplier_id: number; client_id: number; member_number: string; created_by: number; created_at?: string
  company_name?: string; first_name?: string; last_name?: string; revision_no: number | null; effective_from: string | null; status: MemberStatus | null
}
export interface MemberPage { items: MemberRow[]; next_after_id: number | null }
export interface MembershipListFilters {search?: string; year?: number | null; title_key?: string; payment_status?: 'all' | 'paid' | 'unpaid' | 'unproven' | 'none'}

export interface MemberIdentity { id: number; supplier_id: number; client_id: number; member_number: string; created_by: number; created_at?: string }
export interface MemberRevision {
  id: number; supplier_id: number; member_id: number; revision_no: number; previous_id: number | null
  effective_from: string; status: MemberStatus; legal_basis: MembershipLegalBasis
  idempotency_key: string; request_hash: string; parent_hash: string; record_hash: string; created_by: number; created_at?: string
}
export interface MemberRevisionInput { status: MemberStatus; effective_from: string; legal_basis: MembershipLegalBasis; expected_revision: number; idempotency_key: string }

export interface ClaimSettlement { status: SettlementStatus; paid_cents: number | null; open_cents: number | null; pairing_id: number | null; evidence: string }
export interface ClaimRow {
  claim_id: number; case_id: number; operation_id: number; member_id: number; client_id: number; amount_cents: number
  reversal_claim_id: number | null; accounting_date: string; due_date: string; journal_entry_id: number; receivable_line_id: number
  line_no: number; account_id: number; posted_at: string | null; reversed_by: number | null
  title_key: string; period_from: string; period_to: string; settlement: ClaimSettlement
}
export interface MemberBalance {
  member_id: number; page_active_claim_cents: number; page_proven_open_cents: number; page_unreconciled_count: number
  items: ClaimRow[]; next_after_claim_id: number | null
}
export interface MemberDetail { member: MemberIdentity; history: MemberRevision[]; balance: MemberBalance }

export interface ReconciliationTotals {
  claims: { row_count: number | string; cents: number | string }
  operations: { row_count: number | string; cents: number | string }
  ledger: { operation_count: number | string; receivable_cents: number | string; revenue_cents: number | string; invalid_count: number | string | null }
  status: 'balanced' | 'mismatch'
  difference_cents: { claims_to_operations: number; operations_to_receivable: number; receivable_to_revenue: number }
  proof_complete: boolean
}

/** Výslovné účetní členění předpisu: tři hodnoty tenantových os, žádný default ani odvození. */
export interface MembershipClassification { activity_value_id: number; activity_kind_value_id: number; tax_regime_value_id: number }

/** Přesná množina klíčů, kterou přijímá MembershipChargeRequest::issue. */
export interface MembershipIssueRequest {
  rate_id?: number
  member_id: number; title_key: string; period_from: string; period_to: string; accounting_date: string; due_date: string
  amount: string; receivable_account: string; revenue_account: string; title_reference: string; evidence: string
  nonreciprocal: true; reason: string; classification: MembershipClassification
}

/** Původ hodnoty určený serverem; v tomto toku vždy výslovná volba bez odkazu. */
export interface ClassificationOrigin { origin: 'explicit'; ref: null }
export type ActivitySemanticKey = 'main' | 'economic'
export type TaxRegimeSemanticKey = 'subject' | 'out_of_scope' | 'exempt'
/** Normalizované členění, které server ověřil v tenantových osách. */
export interface MembershipProofClassification {
  activity: { type_id: number; value_id: number; semantic_key: ActivitySemanticKey; origin: ClassificationOrigin }
  activity_kind: { type_id: number; value_id: number; code: string; origin: ClassificationOrigin }
  tax_regime: { type_id: number; value_id: number; semantic_key: TaxRegimeSemanticKey; origin: ClassificationOrigin }
}
/** Přesná množina klíčů, kterou přijímá MembershipChargeRequest::reversal. */
export interface MembershipReverseRequest { case_id: number; accounting_date: string; reason: string }

/** Řádek plánu zápisu; `dimensions` a `dimension_origins` mapují ID typu dimenze na ID hodnoty, resp. její původ. */
export interface JournalPlanLine {
  account_code: string; side: 'debit' | 'credit'; amount: string
  dimensions?: Record<number, number>; dimension_origins?: Record<number, ClassificationOrigin>
}
export interface MembershipPreview {
  request: Record<string, unknown>; case_id: number | null; expected_case_version: number; preview_hash: string
  case: { id: number; row_version: number } | null
  proof?: {
    member_revision?: { id: number; revision_no: number; effective_from: string; status: MemberStatus }
    legal_profile?: { id: number; record_hash?: string; profile?: { entity_type?: string; main_activity_nonbusiness?: boolean } }
    accounting_period?: { id: number; fiscal_year: number; starts_on: string; ends_on: string; status: string }
    accounts?: { receivable?: { account_code: string; name: string }; revenue?: { account_code: string; name: string } }
    classification?: MembershipProofClassification
  }
  journal_plan?: JournalPlanLine[]
  deferral?: { mechanism: 'closing.deferred_revenue_accrual'; balance_sheet_date: string; deferred_amount: string } | null
  original_operation_id?: number; original_record_hash?: string
  original?: { id: number; amount_cents?: number; proof?: { accounts?: { receivable?: { account_code: string; name: string }; revenue?: { account_code: string; name: string } } } }
  target_period?: { id: number; fiscal_year: number; starts_on: string; ends_on: string; status: string }
}
export type MembershipPreviewBody =
  | { operation: 'issue'; request: MembershipIssueRequest }
  | { operation: 'reverse'; request: MembershipReverseRequest }
export type MembershipConfirmBody =
  | { operation: 'issue'; request: MembershipIssueRequest; expected_case_version: number; preview_hash: string; idempotency_key: string }
  | { operation: 'reverse'; request: MembershipReverseRequest; expected_case_version: number; preview_hash: string; idempotency_key: string }
export interface MembershipConfirmResult { case: { id: number; row_version: number }; operation: { id: number; operation: MembershipOperation }; idempotent_replay: boolean }

function options(supplier: number) {
  const requested = requestSupplierId(), store = useSupplierStore()
  if (!Number.isSafeInteger(supplier) || supplier <= 0 || (requested !== null ? Number(requested) !== supplier : !store.domainLocked || store.currentSupplierId !== supplier)) throw new Error('nonprofit_membership_supplier_changed')
  return { headers: { 'X-Supplier-Id': String(supplier) } }
}
const base = '/accounting/nonprofit-membership'

export interface MembershipRateInput {
  name: string; amount: string; receivable_account: string; revenue_account: string; title_key: string
  period_months: number; title_reference: string; evidence: string; classification: MembershipClassification | null
  is_active: boolean; row_version: number
}
export interface MembershipRate extends MembershipRateInput { id: number; supplier_id: number }
export interface MembershipRatePage { items: MembershipRate[]; next_after_id: number | null; revenue_accounts?: {account_code: string; name: string}[] }
export type MembershipBatchTemplate = Omit<MembershipIssueRequest, 'member_id'>
export type MembershipBatchPhase = 'seeding' | 'preparing' | 'ready' | 'posting' | 'finished' | 'cancelled'
export type MembershipBatchStatus = 'pending' | 'ready' | 'created' | 'existing' | 'rejected'
export interface MembershipBatchCreate { template: MembershipBatchTemplate; selection_mode: 'active' | 'selected'; member_ids: number[]; idempotency_key: string }
export interface MembershipBatchSummary { total: number; pending: number; ready: number; created: number; existing: number; rejected: number; planned_cents: string; created_cents: string; deferred_cents: string; deferral_date: string | null }
export interface MembershipBatchItem { member_id: number; member_number: string; company_name: string; first_name?: string; last_name?: string; status: MembershipBatchStatus; amount_cents: number; operation_id: number | null; error_code: string | null; error_message: string | null }
export interface MembershipBatchDetail {
  batch: { id: number; supplier_id: number; phase: MembershipBatchPhase; template: MembershipBatchTemplate; approval_token?: string | null; approval_expires_at?: string | null; member_count: number; selection_mode: 'active' | 'selected' }
  summary: MembershipBatchSummary; items: MembershipBatchItem[]; next_after_member_id: number | null
  accounts: { account: string; side: 'debit' | 'credit'; amount_cents: string }[]
}
export interface MembershipBatchRecent { id: number; phase: MembershipBatchPhase; member_count: number; created_at: string; title_key: string; period_from: string; period_to: string }

export const membershipApi = {
  batches: (supplier: number, beforeId?: number) => api.get<{items: MembershipBatchRecent[]; next_before_id: number | null}>(`${base}/batches`, { ...options(supplier), params: { ...(beforeId ? {before_id: beforeId} : {}) } }).then(r => r.data),
  batch: (supplier: number, id: number, afterMemberId = 0) => api.get<MembershipBatchDetail>(`${base}/batches/${id}`, { ...options(supplier), params: { after_member_id: afterMemberId } }).then(r => r.data),
  createBatch: (supplier: number, body: MembershipBatchCreate) => api.post<MembershipBatchDetail>(`${base}/batches`, body, options(supplier)).then(r => r.data),
  prepareBatch: (supplier: number, id: number) => api.post<MembershipBatchDetail>(`${base}/batches/${id}/prepare`, {}, options(supplier)).then(r => r.data),
  renewBatch: (supplier: number, id: number) => api.post<MembershipBatchDetail>(`${base}/batches/${id}/renew-preview`, {}, options(supplier)).then(r => r.data),
  cancelBatch: (supplier: number, id: number) => api.post<MembershipBatchDetail>(`${base}/batches/${id}/cancel`, {}, options(supplier)).then(r => r.data),
  confirmBatch: (supplier: number, id: number, token: string) => api.post<MembershipBatchDetail>(`${base}/batches/${id}/confirm`, {approval_token: token}, options(supplier)).then(r => r.data),
  rate: (supplier: number, id: number) => api.get<MembershipRate>(`${base}/rates/${id}`, options(supplier)).then(r => r.data),
  rates: (supplier: number, afterId = 0, activeOnly = true, date?: string) => api.get<MembershipRatePage>(`${base}/rates`, { ...options(supplier), params: { after_id: afterId, active_only: activeOnly ? 1 : 0, ...(date ? {date} : {}) } }).then(r => r.data),
  createRate: (supplier: number, body: MembershipRateInput) => api.post<MembershipRate>(`${base}/rates`, body, options(supplier)).then(r => r.data),
  updateRate: (supplier: number, id: number, body: MembershipRateInput) => api.put<MembershipRate>(`${base}/rates/${id}`, body, options(supplier)).then(r => r.data),
  selectionCheck: (supplier: number, memberIds: number[], filters: MembershipListFilters) => api.post<{member_ids: number[]}>(`${base}/members/selection-check`, {member_ids: memberIds, filters}, options(supplier)).then(r => r.data),
  titles: (supplier: number, year?: number, afterTitleKey?: string) => api.get<{items: string[]; next_after_title_key: string | null}>(`${base}/titles`, { ...options(supplier), params: { ...(year ? {year} : {}), ...(afterTitleKey ? {after_title_key: afterTitleKey} : {}), limit: 100 } }).then(r => r.data),
  members: (supplier: number, afterId = 0, limit = 50, filters: MembershipListFilters = {}) => api.get<MemberPage>(`${base}/members`, { ...options(supplier), params: { after_id: afterId, limit, ...filters, ...(filters.year === null ? {year: ''} : {}) } }).then(r => r.data),
  member: (supplier: number, id: number, afterClaimId = 0, limit = 50) => api.get<MemberDetail>(`${base}/members/${id}`, { ...options(supplier), params: { after_claim_id: afterClaimId, limit } }).then(r => r.data),
  claim: (supplier: number, id: number) => api.get<ClaimRow>(`${base}/claims/${id}`, options(supplier)).then(r => r.data),
  classificationOptions: (supplier: number) => api.get<DimensionOverview>('/accounting/dimensions', options(supplier)).then(r => r.data),
  reconciliation: (supplier: number, period: number) => api.get<ReconciliationTotals>(`/accounting/periods/${period}/nonprofit-membership/reconciliation`, options(supplier)).then(r => r.data),
  createMember: (supplier: number, body: { client_id: number; member_number: string }) => api.post<MemberIdentity>(`${base}/members`, body, options(supplier)).then(r => r.data),
  appendRevision: (supplier: number, member: number, body: MemberRevisionInput) => api.post<MemberRevision>(`${base}/members/${member}/revisions`, body, options(supplier)).then(r => r.data),
  preview: (supplier: number, body: MembershipPreviewBody) => api.post<MembershipPreview>(`${base}/charges/preview`, body, options(supplier)).then(r => r.data),
  confirm: (supplier: number, body: MembershipConfirmBody) => api.post<MembershipConfirmResult>(`${base}/charges/confirm`, body, options(supplier)).then(r => r.data),
}

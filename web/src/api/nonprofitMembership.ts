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
  id: number; supplier_id: number; client_id: number; member_number: string; created_by: number; created_at?: string
  company_name?: string; revision_no: number | null; effective_from: string | null; status: MemberStatus | null
}
export interface MemberPage { items: MemberRow[]; next_after_id: number | null }

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

export const membershipApi = {
  members: (supplier: number, afterId = 0, limit = 50) => api.get<MemberPage>(`${base}/members`, { ...options(supplier), params: { after_id: afterId, limit } }).then(r => r.data),
  member: (supplier: number, id: number, afterClaimId = 0, limit = 50) => api.get<MemberDetail>(`${base}/members/${id}`, { ...options(supplier), params: { after_claim_id: afterClaimId, limit } }).then(r => r.data),
  claim: (supplier: number, id: number) => api.get<ClaimRow>(`${base}/claims/${id}`, options(supplier)).then(r => r.data),
  classificationOptions: (supplier: number) => api.get<DimensionOverview>('/accounting/dimensions', options(supplier)).then(r => r.data),
  reconciliation: (supplier: number, period: number) => api.get<ReconciliationTotals>(`/accounting/periods/${period}/nonprofit-membership/reconciliation`, options(supplier)).then(r => r.data),
  createMember: (supplier: number, body: { client_id: number; member_number: string }) => api.post<MemberIdentity>(`${base}/members`, body, options(supplier)).then(r => r.data),
  appendRevision: (supplier: number, member: number, body: MemberRevisionInput) => api.post<MemberRevision>(`${base}/members/${member}/revisions`, body, options(supplier)).then(r => r.data),
  preview: (supplier: number, body: MembershipPreviewBody) => api.post<MembershipPreview>(`${base}/charges/preview`, body, options(supplier)).then(r => r.data),
  confirm: (supplier: number, body: MembershipConfirmBody) => api.post<MembershipConfirmResult>(`${base}/charges/confirm`, body, options(supplier)).then(r => r.data),
}

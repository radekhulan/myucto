import { api } from './client'
import type { PaymentComponent, PaymentFacts } from './propertyPayments'

export interface OpsParty { id: number; label: string }
export interface OpsCoverage { scope: 'recorded_s3_s4'; complete: false }
export interface OpsPage<T> { items: T[]; next_cursor: number | null; coverage: OpsCoverage }
export interface OpsQuery { unit_id?: number; after_id?: number; limit?: number }
export type OpsCollection = 'debts' | 'charges' | 'credits' | 'reservations' | 'operations' | 'corrections'

export interface DebtGroup { group_key: string; role: 'debtor' | 'guarantor'; members: OpsParty[]; remaining_cap_cents: string | null }
export interface DebtLegal { basis: 'statutory' | 'legal_act'; legal_act_date?: string; evidence: string; regime: 'general' | 'consumer_in_default'; component_disposition?: ComponentDisposition }
export interface ComponentDisposition { order: PaymentComponent[]; debtor_evidence: string; creditor_consent?: string; old_interest_effect?: { resolution: 'none_documented' | 'separately_evidenced'; evidence: string } }
export interface DebtSecurity { kind: 'secured' | 'unsecured'; evidence: string }
export interface Debt { id: number; charge_id: number | null; unit_id: number; unit_label: string; title_ref: string; due_date: string; amount_cents: string; remaining_cents: string }
export interface DebtDetail extends Debt { legal: DebtLegal; security: DebtSecurity; liability_as_of: string | null; liability_complete: boolean; groups: DebtGroup[]; lines: Array<{ id: number; label: string; amount_cents: string; remaining_cents: string }>; accessories: Array<{ id: number; component: PaymentComponent; label: string; amount_cents: string; remaining_cents: string }> }
export interface Charge { id: number; row_version: number; unit_id: number; unit_label: string; accounting_date: string; due_date: string; status: string; amount_cents: string; previous_charge_id: number | null }
export interface ChargeDetail extends Charge { lines: Array<{ id: number; label: string; amount_cents: string; component_id: number | null; registered_debt_id: number | null }>; parties: OpsParty[] }
export interface Credit { id: number; row_version: number; unit_id: number; unit_label: string; amount_cents: string; balance_cents: string; reserved_cents: string; available_cents: string; due_date: string; parties: OpsParty[]; operation_id: number | null }
export interface ReservationRequest { amount_cents: string; party_ids: number[]; disposition: string; delivery_method: 'bank' | 'cash'; recipient_account?: string; recipient_id?: number; recipient_evidence?: string }
export interface Reservation { id: number; credit_id: number; row_version: number; status: 'reserved' | 'submitted' | 'paid' | 'cancelled' | 'failed'; amount_cents: string; paid_cents: string; remaining_cents: string; unit_id: number; unit_label: string; parties: OpsParty[]; request: ReservationRequest; cancel_requested?: boolean }
export interface Operation { id: number; kind: string; status: string; row_version: number; amount_cents: string; accounting_date: string; reason: string; source_label: string | null; unit_labels: string[]; credit_id: number | null }
export interface OperationDetail extends Operation { allocations: Array<{ id: number; debt_id: number; charge_line_id: number | null; accessory_id: number | null; label: string; amount_cents: string }> }
export interface CorrectionAnchor { adjustment_line_id: number; original_line_id: number; amount_cents: string; evidence: string }
export interface CorrectionRelief { allocation_id: number; amount_cents: string }
export interface CorrectionCreditEntitlement { party_ids: number[]; evidence: string; due_date: string }
export interface CorrectionRequest { adjustment_charge_id: number; expected_version: number; accounting_date: string; reason: string; anchors: CorrectionAnchor[]; reliefs: CorrectionRelief[]; credit_entitlement?: CorrectionCreditEntitlement }
export interface Correction { id: number; status: string; row_version: number; adjustment_charge_id: number; operation_id: number | null; credit_id: number | null; relief_cents: string; accounting_date: string; reason: string; unit_id: number; unit_label: string }
export interface CorrectionDetail extends Correction { anchors: CorrectionAnchor[]; reliefs: CorrectionRelief[] }
export interface CorrectionOptions { charge: ChargeDetail; original_lines: Array<{ id: number; label: string; amount_cents: string }>; allocations: Array<{ id: number; original_line_id: number; label: string; amount_cents: string; available_relief_cents: string }>; parties: OpsParty[] }

export interface CardGroup { group_key: string; role: 'debtor' | 'guarantor'; members: OpsParty[]; remaining_cap_cents: string | null }
export type CardPrecision = 'instant' | 'day'
export interface CardClaim { claim_key: string; debt_id: number | null; unit_id: number; unit_label: string; title_ref: string; due_date: string; amount_cents: string | null; person_liability_cents: string | null; groups: CardGroup[]; precision: CardPrecision; complete: boolean }
export interface CardCredit { id: number; unit_id: number; unit_label: string; parties: OpsParty[]; gross_credit_cents: string | null; reserved_credit_cents: string | null; available_credit_cents: string | null; precision: CardPrecision; complete: boolean }
export interface CardTotals { gross_receivable_cents: string | null; gross_credit_cents: string | null; reserved_credit_cents: string | null; available_credit_cents: string | null; guarantee_exposure_cents: string | null }
export interface PropertyCard { as_of: string; known_at: string; scope: string; claims: CardClaim[]; credits: CardCredit[]; totals: CardTotals; precision: CardPrecision; coverage: { financial_complete: boolean; service_settlements: 'none' | 'pending' | 'applied'; opening_import: 'verified_recorded_batches'; scope: 'recorded_s3_s4_s6'; complete: false }; snapshot_hash: string }

export interface DebtRegistration { charge_id: number; expected_version: number; principal_line_ids: number[]; title_ref: string; due_date: string; legal: DebtLegal; security: DebtSecurity; damages_for?: number | null }
export interface SetoffLegal { certain: true; unconditional: true; scope_cents: string; mutuality_evidence: string; eligibility_evidence: string; declaration_evidence: string; delivery_evidence: string; delivered_at: string; effect_evidence: string; early_performance_evidence?: string; scope_1984_evidence?: string }
export interface SetoffRequest { kind: 'setoff'; credit_id: number; expected_credit_version: number; amount_cents: string; accounting_date: string; reason: string; debts: number[]; payment: PaymentFacts; legal: SetoffLegal }
export interface AboPaymentSymbols { variable_symbol?: string; constant_symbol?: string; specific_symbol?: string; allow_missing_variable_symbol?: boolean; message?: string }
export interface AboRequest { reservation_ids: number[]; expected_versions: Record<number, number>; payment_symbols: Record<number, AboPaymentSymbols>; bank_account_id: number; due_date: string; reason: string }
export type ReservationStatus = 'reserved' | 'submitted' | 'paid' | 'cancelled'
export type AboMetadata = Omit<AboResult, 'content_base64'>
export interface AboResult { id: number; filename: string; content_base64: string; encoding: 'windows-1250'; reservation_ids: number[]; reservation_statuses: Record<number, 'submitted' | 'paid' | 'cancelled'> }
export interface OpsPreview { preview_hash: string; snapshot: Record<string, unknown> }
export interface OpsBankAccount { id: number; label: string | null; account_number: string; bank_code: string }
export interface AccessoryCandidate { id: number; journal_entry_id: number; date: string; document_number: string | null; label: string; account_code: string; amount_cents: string }
export interface AccessoryCandidatePage { items: AccessoryCandidate[]; next_cursor: number | null; coverage: { eligibility: 'current_receipt_required' } }
export interface AccessoryBinding { journal_entry_id: number; journal_line_id: number; component: Exclude<PaymentComponent, 'principal'>; evidence: string; liability_evidence: string; purpose?: 'old_1932_interest_effect' }
export interface CorrectionReverseRequest { expected_version: number; accounting_date: string; effective_at: string; reason: string; evidence: string }
export interface OperationReverseRequest { expected_version: number; accounting_date: string; reason: string }

const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
const base = '/property/payments'

export const propertyPaymentOperationsApi = {
  list: <T>(supplier: number, collection: OpsCollection, query: OpsQuery = {}) => api.get<OpsPage<T>>(`${base}/${collection}`, { ...options(supplier), params: query }).then(r => r.data),
  detail: <T>(supplier: number, collection: OpsCollection, id: number) => api.get<T>(`${base}/${collection}/${id}`, options(supplier)).then(r => r.data),
  parties: (supplier: number, query: string, afterId?: number, limit?: number) => api.get<{ items: OpsParty[]; next_cursor: number | null; coverage: OpsCoverage }>(`${base}/parties`, { ...options(supplier), params: { query, after_id: afterId, limit } }).then(r => r.data),
  correctionOptions: (supplier: number, adjustmentChargeId: number) => api.get<CorrectionOptions>(`${base}/correction-options`, { ...options(supplier), params: { adjustment_charge_id: adjustmentChargeId } }).then(r => r.data),
  card: (supplier: number, query: { as_of: string; known_at: string; unit_id?: number; client_id?: number }) => api.get<PropertyCard>(`${base}/card`, { ...options(supplier), params: query }).then(r => r.data),
  bankAccounts: (supplier: number) => api.get<{ items: OpsBankAccount[]; coverage: { currency: 'CZK'; eligibility: 'current_preview_required' } }>(`${base}/bank-accounts`, options(supplier)).then(r => r.data.items),
  accessoryCandidates: (supplier: number, afterId: number, limit = 25) => api.get<AccessoryCandidatePage>(`${base}/accessory-candidates`, { ...options(supplier), params: { after_id: afterId, limit } }).then(r => r.data),
  bindAccessory: (supplier: number, debtId: number, request: AccessoryBinding) => api.post<{ id: number }>(`${base}/debts/${debtId}/accessories`, request, options(supplier)).then(r => r.data),
  aboMetadata: (supplier: number, reservationId: number) => api.get<{ export: AboMetadata | null }>(`${base}/reservations/${reservationId}/abo-export`, options(supplier)).then(r => r.data.export),
  aboDownload: (supplier: number, exportId: number) => api.post<AboResult>(`${base}/reservations/abo/${exportId}/download`, {}, options(supplier)).then(r => r.data),
  registerDebt: (supplier: number, request: DebtRegistration, token: string) => api.post<{ id: number; row_version?: number }>(`${base}/debts/register`, { request, idempotency_key: token }, options(supplier)).then(r => r.data),
  reserveCredit: (supplier: number, creditId: number, expectedVersion: number, request: ReservationRequest, token: string) => api.post<{ id: number; row_version?: number }>(`${base}/credits/${creditId}/reserve`, { expected_version: expectedVersion, request, idempotency_key: token }, options(supplier)).then(r => r.data),
  cancelReservation: (supplier: number, id: number, expectedVersion: number, reason: string, token: string) => api.post<{ id: number; row_version?: number }>(`${base}/reservations/${id}/cancel`, { expected_version: expectedVersion, reason, idempotency_key: token }, options(supplier)).then(r => r.data),
  cancelSubmission: (supplier: number, id: number, expectedVersion: number, reason: string, evidence: string, token: string) => api.post<{ id: number; row_version?: number; cancel_requested?: boolean }>(`${base}/reservations/${id}/cancel-submission`, { expected_version: expectedVersion, reason, evidence, idempotency_key: token }, options(supplier)).then(r => r.data),
  releaseUnexecuted: (supplier: number, id: number, expectedVersion: number, statementId: number, evidence: string, token: string) => api.post<{ id: number; row_version: number; status: string; cancel_requested: boolean }>(`${base}/reservations/${id}/release-unexecuted`, { expected_version: expectedVersion, statement_id: statementId, evidence, idempotency_key: token }, options(supplier)).then(r => r.data),
  aboPreview: (supplier: number, request: AboRequest) => api.post<OpsPreview>(`${base}/reservations/abo/preview`, request, options(supplier)).then(r => r.data),
  aboConfirm: (supplier: number, request: AboRequest, hash: string, token: string) => api.post<AboResult>(`${base}/reservations/abo/confirm`, { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  setoffPreview: (supplier: number, request: SetoffRequest) => api.post<OpsPreview>(`${base}/setoffs/preview`, request, options(supplier)).then(r => r.data),
  setoffConfirm: (supplier: number, request: SetoffRequest, hash: string, token: string) => api.post<{ id: number }>(`${base}/setoffs/confirm`, { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  correctionPreview: (supplier: number, request: CorrectionRequest) => api.post<OpsPreview>(`${base}/corrections/preview`, request, options(supplier)).then(r => r.data),
  correctionConfirm: (supplier: number, request: CorrectionRequest, hash: string, token: string) => api.post<{ id: number }>(`${base}/corrections/confirm`, { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  reverseOperation: (supplier: number, id: number, request: OperationReverseRequest, token: string) => api.post<{ id: number }>(`${base}/operations/${id}/reverse`, { ...request, idempotency_key: token }, options(supplier)).then(r => r.data),
  reverseCorrection: (supplier: number, id: number, request: CorrectionReverseRequest, token: string) => api.post<{ id: number }>(`${base}/corrections/${id}/reverse`, { request, idempotency_key: token }, options(supplier)).then(r => r.data),
}

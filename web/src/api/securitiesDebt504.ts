import { api } from './client'
import type { SecuritiesProfile } from './securities504'
export interface DebtCouponPeriod { from: string; to_exclusive: string; annual_rate_fraction: string; day_count: string; frequency?: number; rounding: string; terms_reference: string }
export interface DebtTerms { lot_reference: string; instrument_identity: string; contract_reference: string; classification_reference: string; debt_type: 'coupon' | 'discount'; classification: 'trading' | 'held_to_maturity'; acquired_on: string; maturity_on: string; nominal: string; clean_cost: string; acquired_interest: string; clean_cost_czk: string; acquired_interest_czk: string; coupon_periods: DebtCouponPeriod[]; amortisation?: { method: string; policy_reference: string; rounding: string } }
export type DebtOperation = 'register' | 'accrual' | 'amortisation' | 'valuation' | 'coupon_receipt' | 'principal_redemption' | 'sale' | 'reverse'
export type DebtQuote = Record<string, string>
export type DebtCommand = { operation: 'register'; terms: DebtTerms; entry_id: number; line_id: number } | { operation: Exclude<DebtOperation, 'register'>; lot_id: number; accounting_date: string; evidence: string; entry_id?: number; line_id?: number; nominal_amount?: string; event_id?: number; profile?: SecuritiesProfile; quote?: DebtQuote }
export interface DebtSource { id: number; entry_date: string; document_no: string | null; description: string | null; line_id: number; account_code: string; amount: string; currency_code: string | null; amount_foreign: string | null }
export interface DebtSourceCursor { entry_id: number; line_id: number }
export interface DebtCard { id: number; lot_reference: string; acquired_on: string; maturity_on: string }
export interface DebtLine { account_code: string; side: 'debit' | 'credit'; amount: string; amount_foreign?: string | null; currency_code?: string | null }
export interface DebtPreview { state_hash: string; preview_hash: string; plan: { operation: DebtOperation; lines: DebtLine[]; amount?: string; book_change_czk?: string; facts?: { terms: DebtTerms; currency: string }; payment?: { date: string; amount: string; money_amount: string; currency: string }; sold?: { principal: string; interest: string; principal_czk: string; interest_czk: string } } }
export interface DebtState { id: number; facts: { terms: DebtTerms; currency: string }; earned: string; amortised: string; unpaid_interest: string; principal_balance: string; principal_book_czk: string; interest_book_czk: string; book_czk: string; sold_on: string | null; hash: string; events: Array<{ id: number; request: { operation: DebtOperation; accounting_date: string; evidence: string }; receipt: { amount: string; book_change_czk: string; ledger?: unknown; payment?: unknown } }> }
export interface DebtProfileChoices { periods: Array<{ id: number; starts_on: string; ends_on: string; fiscal_year: number }>; history: Array<{ period_id: number; assets_net: string; net_turnover: string; avg_employees: number }> }
const path = '/accounting/securities/debt'
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const securitiesDebt504Api = {
 list: (supplier: number, after_id = 0) => api.get<{ rows: DebtCard[]; financial_enabled: boolean; framework_supported: boolean; can_write: boolean }>(path, { ...options(supplier), params: { after_id } }).then(r => r.data),
 detail: (supplier: number, id: number, date: string) => api.get<DebtState>(`${path}/${id}`, { ...options(supplier), params: { date } }).then(r => r.data),
 sources: (supplier: number, kind: 'acquisitions' | 'receipts' | 'proceeds', date: string, cursor: DebtSourceCursor | null = null) => api.get<{ rows: DebtSource[]; next_cursor: DebtSourceCursor | null }>(`${path}/sources/${kind}`, { ...options(supplier), params: { date, after_entry_id: cursor?.entry_id || 0, after_line_id: cursor?.line_id || 0 } }).then(r => r.data),
 profileChoices: (supplier: number, date: string) => api.get<DebtProfileChoices>(`${path}/profile-choices`, { ...options(supplier), params: { date } }).then(r => r.data),
 preview: (supplier: number, command: DebtCommand) => api.post<DebtPreview>(`${path}/preview`, { command }, options(supplier)).then(r => r.data),
 execute: (supplier: number, command: DebtCommand, preview: DebtPreview, idempotency_key: string) => api.post<{ lot_id: number; event_id: number | null }>(`${path}/commands`, { command, state_hash: preview.state_hash, preview_hash: preview.preview_hash, idempotency_key }, options(supplier)).then(r => r.data),
}

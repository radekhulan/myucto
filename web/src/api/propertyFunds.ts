import { api } from './client'
import type { Ownership, PropertyObject } from './propertyRegistry'
export type FundingKind = 'draw' | 'income' | 'transfer' | 'payout' | 'reverse'
export type FundingClassification = 'repair' | 'improvement' | 'administration'
export interface FundingKey { mode: 'at_accounting_date' | 'resolution_snapshot'; at: string; method: 'share' | 'equal'; legal_basis: 'management_share' | 'own_administration_equal' | 'declaration_override'; evidence_ref: string }
export interface FundingPointer { kind: 'opening' | 'issued' | 'transfer'; id: number }
export interface FundingEffect { id: number; unit_id: number; account_code: string; amount_cents: string; source_kind: string | null; source_id: number | null; parties: Ownership | null }
export interface FundingDisposition { source_id: number; amount_cents: string; party_ids: number[]; evidence_ref: string; recipient_authority_ref?: string }
interface FundingCommon { kind: FundingKind; house_id: number; accounting_date: string; expected_revision: number; evidence_ref: string; reason: string }
export type FundingCommand = FundingCommon & (
 { kind: 'draw'; amount_cents: string; key: FundingKey; classification: FundingClassification; ownership: 'owners_common_parts'; cost_line_id: number; other_coverage: Array<{ line_id: number; amount_cents: string; evidence_ref: string }>; sources: Array<{ source: FundingPointer; amount_cents: string; purpose_evidence: string }> }
 | { kind: 'income'; amount_cents: string; key: FundingKey; bank_id: number }
 | { kind: 'transfer'; amount_cents: string; dispositions: FundingDisposition[]; target: 'long_term' | 'admin'; purpose_ref: string; settlement_due_on: string }
 | { kind: 'payout'; amount_cents: string; dispositions: FundingDisposition[]; bank_id: number; recipient_id: number; recipient_account: string }
 | { kind: 'reverse'; operation_id: number })
export interface FundingJournalLine { id?: number; account_code: string; side: 'debit' | 'credit'; amount: string; dimensions?: Record<number, number>; cost_center?: string }
export interface FundingPlan { request: FundingCommand; effects: FundingEffect[]; journal: { header: { entry_date: string }; lines: FundingJournalLine[] }; proofs: { registry?: { calculation: { lines: Array<{ unit_id: number; amount_cents: string }> } } } }
export interface FundingPreview { preview_hash: string; plan: FundingPlan }
export interface FundingOperation { id: number; house_id: number; kind: FundingKind; accounting_date: string; recorded_at: string; active: boolean; journal_entry_id: number; request: FundingCommand; receipt: { effects: FundingEffect[]; ledger: { lines: FundingJournalLine[] } } }
export interface FundingAdvance { source: FundingPointer; unit_id: number; account_code: string; amount_cents: string; purpose_ref: string }
export interface FundingIncomeLot extends FundingEffect { operation_id: number; accounting_date: string; remaining_cents: string }
export interface FundingBalances { revision: number | null; totals: Record<string, string>; ledger_totals: Record<string, string>; unit_balances: Record<number, Record<string, string>>; differences: Record<number, Record<string, string>>; matches: boolean; unregistered_line_ids: number[]; sources: FundingAdvance[]; income_lots: FundingIncomeLot[] }
export interface FundingChoices { houses: PropertyObject[]; units: PropertyObject[]; accounts: Array<{ id: number; account_code: string; name: string }> }
export interface FundingCostSource { description?: string; entry_date?: string; account_name?: string; id: number; classification: FundingClassification; amount_cents: string; line: FundingJournalLine; ledger: { entry: { entry_date: string }; lines: FundingJournalLine[] } }
export interface FundingBankSource { id: number; direction: 'in' | 'out'; date: string; amount_cents: string; money_account: string; raw: { counterparty_name?: string; counterparty_account?: string; counterparty_bank?: string } }
export interface FundingCoverageSource { description?: string; entry_date?: string; account_name?: string; id: number; amount_cents: string; line: FundingJournalLine; ledger: { entry: { entry_date: string }; lines: FundingJournalLine[] } }
export interface FundingSourcePage<T> { items: T[]; next_after_id: number | null; incomplete: Array<{ id: number; reason: string }> }
const path = '/property/funds'
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const propertyFundsApi = {
 choices: (supplier: number, date: string) => api.get<FundingChoices>(`${path}/choices`, { ...options(supplier), params: { date } }).then(r => r.data),
 balances: (supplier: number, query: { date: string; house_id?: number; unit_id?: number; known_at?: string }) => api.get<FundingBalances>(`${path}/balances`, { ...options(supplier), params: query }).then(r => r.data),
 list: (supplier: number, house_id: number) => api.get<{ revision: number; items: FundingOperation[] }>(path, { ...options(supplier), params: { house_id } }).then(r => r.data),
 costs: (supplier: number, date: string, classification: FundingClassification, after_id = 0) => api.get<FundingSourcePage<FundingCostSource>>(`${path}/sources/costs`, { ...options(supplier), params: { date, classification, ...(after_id ? { after_id } : {}) } }).then(r => r.data),
 banks: (supplier: number, date: string, after_id = 0) => api.get<FundingSourcePage<FundingBankSource>>(`${path}/sources/banks`, { ...options(supplier), params: { date, ...(after_id ? { after_id } : {}) } }).then(r => r.data),
 coverages: (supplier: number, date: string, after_id = 0) => api.get<FundingSourcePage<FundingCoverageSource>>(`${path}/sources/coverages`, { ...options(supplier), params: { date, ...(after_id ? { after_id } : {}) } }).then(r => r.data),
 preview: (supplier: number, command: FundingCommand) => api.post<FundingPreview>(`${path}/preview`, { command }, options(supplier)).then(r => r.data),
 execute: (supplier: number, command: FundingCommand, preview: FundingPreview, idempotency_key: string) => api.post<FundingOperation>(`${path}/commands`, { command, preview_hash: preview.preview_hash, idempotency_key }, options(supplier)).then(r => r.data),
}

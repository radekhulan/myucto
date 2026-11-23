import { api } from './client'
import type { FundingKind } from './nonprofitFunding'

/** Pracovní evidence čerpání dotací (#403): interní routy /accounting/periods/{id}/funding-work. */
export type FundingWorkState = 'ok' | 'changed' | 'removed' | 'reversed'
export interface FundingWorkPeriod { id: number; starts_on: string; ends_on: string; status: string }
export interface FundingWorkLine {
 id: number; entry_id: number; period_id: number; account_id: number; account_code: string; account_name: string; side: 'debit' | 'credit'
 amount_cents: number; date: string; document_no: string | null; description: string | null; source_type: string; source_id: number | null
 posted: boolean; reversed: boolean; is_reversal: boolean; activity_kind_id: number | null
}
export interface FundingWorkCandidate extends FundingWorkLine { allocated_cents?: number; capacity_cents?: number; confirmed_source_key?: string | null; credit_notes?: number }
export interface FundingWorkSourceInput {
 provider_id: number; kind: FundingKind; title: string; contract_ref: string | null; budget_cents: number; valid_from: string; valid_to: string
 activity_kind_ids: number[]; account_prefixes: string[]; legacy_source_key: string | null
}
export interface FundingWorkSource extends FundingWorkSourceInput {
 id: number; period_id: number; provider_label: string; status: 'active' | 'cancelled'; row_version: number; created_at: string; updated_at: string | null
}
export interface FundingWorkTotals {
 budget_cents: number; received_cents: number; allocated_cents: number; documented_cents: number; remaining_cents: number
 review_count: number; review_cents: number; income_count: number; allocation_count: number
}
interface FundingWorkLink {
 id: number; source_id: number; line_id: number | null; linked_line_id: number; entry_id: number; account_id: number; account_code: string | null
 entry_source_type: string | null; entry_source_id: number | null; entry_date: string; row_version: number; amount_cents: number; state: FundingWorkState
 review_reason: string | null; line: FundingWorkLine | null; relink_candidates?: FundingWorkLine[]; confirmed_source_key?: string | null
}
export type FundingWorkIncome = FundingWorkLink
export interface FundingWorkAllocation extends FundingWorkLink { line_amount_cents: number; note: string | null; payment_state?: 'paid' | 'partial' | 'unpaid' | null }
export interface FundingWorkHistory { id: number; income_id: number | null; allocation_id: number | null; action: string; before: Record<string, unknown> | null; after: Record<string, unknown> | null; reason: string | null; created_at: string; actor: string | null }
export interface FundingWorkOverview { period: FundingWorkPeriod; editable: boolean; sources: Array<FundingWorkSource & { totals: FundingWorkTotals }> }
export interface FundingWorkDetail {
 period: FundingWorkPeriod; editable: boolean; source: FundingWorkSource; totals: FundingWorkTotals
 incomes: FundingWorkIncome[]; allocations: FundingWorkAllocation[]; allocations_page: number; allocations_more: boolean; history: FundingWorkHistory[]
}
export interface FundingWorkChoices {
 providers: Array<{ id: number; label: string }>; providers_more: boolean; activities: Array<{ id: number; label: string }>
 accounts: Array<{ code: string; label: string }>; confirmed_sources: Array<{ key: string; label: string }>
}
export interface FundingWorkCandidates { rows: FundingWorkCandidate[]; more: boolean }
export interface FundingWorkAllocationItem { line_id: number; amount_cents: number; note?: string | null }

const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
const base = (period: number) => `/accounting/periods/${period}/funding-work`

export const fundingWorkApi = {
 overview: (supplier: number, period: number) => api.get<FundingWorkOverview>(base(period), options(supplier)).then(r => r.data),
 choices: (supplier: number, period: number, search = '') => api.get<FundingWorkChoices>(`${base(period)}/choices`, { ...options(supplier), params: { search } }).then(r => r.data),
 incomeCandidates: (supplier: number, period: number, source: number, search: string, page: number) => api.get<FundingWorkCandidates>(`${base(period)}/sources/${source}/income-candidates`, { ...options(supplier), params: { search, page } }).then(r => r.data),
 costCandidates: (supplier: number, period: number, source: number, search: string, page: number) => api.get<FundingWorkCandidates>(`${base(period)}/sources/${source}/cost-candidates`, { ...options(supplier), params: { search, page } }).then(r => r.data),
 detail: (supplier: number, period: number, source: number, page = 1) => api.get<FundingWorkDetail>(`${base(period)}/sources/${source}`, { ...options(supplier), params: { page } }).then(r => r.data),
 create: (supplier: number, period: number, body: FundingWorkSourceInput) => api.post<FundingWorkDetail>(`${base(period)}/sources`, body, options(supplier)).then(r => r.data),
 update: (supplier: number, period: number, source: number, body: FundingWorkSourceInput & { expected_version: number; reason: string }) => api.put<FundingWorkDetail>(`${base(period)}/sources/${source}`, body, options(supplier)).then(r => r.data),
 cancel: (supplier: number, period: number, source: number, expected_version: number, reason: string) => api.post<FundingWorkDetail>(`${base(period)}/sources/${source}/cancel`, { expected_version, reason }, options(supplier)).then(r => r.data),
 addIncomes: (supplier: number, period: number, source: number, line_ids: number[]) => api.post<FundingWorkDetail>(`${base(period)}/sources/${source}/incomes`, { line_ids }, options(supplier)).then(r => r.data),
 removeIncome: (supplier: number, period: number, income: number, expected_version: number, reason: string) => api.post<FundingWorkDetail>(`${base(period)}/incomes/${income}/remove`, { expected_version, reason }, options(supplier)).then(r => r.data),
 relinkIncome: (supplier: number, period: number, income: number, line_id: number, expected_version: number) => api.post<FundingWorkDetail>(`${base(period)}/incomes/${income}/relink`, { line_id, expected_version }, options(supplier)).then(r => r.data),
 allocate: (supplier: number, period: number, source: number, items: FundingWorkAllocationItem[]) => api.post<FundingWorkDetail>(`${base(period)}/sources/${source}/allocations`, { items }, options(supplier)).then(r => r.data),
 updateAllocation: (supplier: number, period: number, allocation: number, amount_cents: number, note: string | null, expected_version: number) => api.put<FundingWorkDetail>(`${base(period)}/allocations/${allocation}`, { amount_cents, note, expected_version }, options(supplier)).then(r => r.data),
 removeAllocation: (supplier: number, period: number, allocation: number, expected_version: number, reason: string) => api.post<FundingWorkDetail>(`${base(period)}/allocations/${allocation}/remove`, { expected_version, reason }, options(supplier)).then(r => r.data),
 relinkAllocation: (supplier: number, period: number, allocation: number, line_id: number, amount_cents: number | null, expected_version: number) => api.post<FundingWorkDetail>(`${base(period)}/allocations/${allocation}/relink`, { line_id, amount_cents, expected_version }, options(supplier)).then(r => r.data),
}

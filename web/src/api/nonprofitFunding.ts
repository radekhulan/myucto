import { api } from './client'
import type { AccountingPeriod } from './accounting'
export type FundingKind = 'gift' | 'operating_grant' | 'investment_grant'
export interface FundingPolicy {
 kind: FundingKind; title: string; purpose: string; evidence: string; provider_id: number; budget_cents: number
 valid_from: string; valid_to: string; activity_kind_ids: number[]; account_prefixes: string[]; source_anchor: string; source_members: string[]
}
export interface FundingSourceRow { id: string; account_code: string; date: string; amount_cents: number; decision: { title: string; facts?: { claim_exemption?: boolean }; evidence: string } }
export interface FundingRevision {
 id: number; period_id: number; source_key: string; revision_no: number; reason: string; created_at: string; policy: FundingPolicy
 basis: { recognized_cents: number; source_rows: Record<string, FundingSourceRow>; provider: { company_name: string; first_name: string | null; last_name: string | null; ic: string | null; street?: string; city?: string; zip?: string } }
}
export interface FundingUse { assessment_id: number; period_id: number; current: boolean; amount_cents: number; evidence: string; cost: { id: string; date: string; account_code: string; activity_kind_id: number; amount_cents: number } }
export interface FundingAmounts { recognized_cents: number | null; budget_cents: number; drawdown_cents: number; budget_remaining_cents: number; available_cents: number | null; uses: FundingUse[] }
export interface FundingSource extends FundingAmounts { source_key: string; revision: FundingRevision; source_current: boolean }
export interface FundingOverview { sources: FundingSource[]; findings: Array<{ code: string; source_key?: string; assessment_id?: number; period_id?: number }>; complete: boolean; financial_authorization: false }
export interface FundingChoices {
 period: AccountingPeriod; assessment_current: boolean; providers: Array<{ id: number; label: string }>; providers_more: boolean
 activities: Array<{ id: number; label: string }>; accounts: Array<{ code: string; label: string }>
 sources: Array<{ anchor: string; members: string[]; kind: FundingKind; date: string; account_code: string; amount_cents: number; evidence: string }>
}
export interface FundingPreview { source_key: string; policy: FundingPolicy; input_hash: string; expected_revision: number; report: FundingAmounts; financial_authorization: false }
export interface FundingConfirmation { source_key: string; policy: FundingPolicy; input_hash: string; expected_revision: number; reason: string; idempotency_key: string }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const fundingApi = {
 read: (supplier: number) => api.get<FundingOverview>('/accounting/nonprofit-funding', options(supplier)).then(r => r.data),
 periods: (supplier: number) => api.get<AccountingPeriod[]>('/accounting/periods', options(supplier)).then(r => r.data),
 choices: (supplier: number, period: number, search = '') => api.get<FundingChoices>(`/accounting/periods/${period}/nonprofit-funding/choices`, { ...options(supplier), params: { search } }).then(r => r.data),
 preview: (supplier: number, period: number, source_key: string, policy: FundingPolicy) => api.post<FundingPreview>(`/accounting/periods/${period}/nonprofit-funding/preview`, { source_key, policy }, options(supplier)).then(r => r.data),
 confirm: (supplier: number, period: number, body: FundingConfirmation) => api.post<FundingRevision>(`/accounting/periods/${period}/nonprofit-funding/confirm`, body, options(supplier)).then(r => r.data),
}

import { api } from './client'
import type { AccountingPeriod } from './accounting'
export interface CapitalPolicy { kind: 'investment' | 'fund'; source_line_id: number; asset_id: number | null; provider_id: number | null; revenue_tax_regime_id: number; title: string; purpose: string; evidence: string; decision_authority: string; valid_from: string; valid_to: string; activity_kind_ids: number[]; account_prefixes: string[] }
export interface CapitalSource { id: number; kind: CapitalPolicy['kind']; asset_id: number | null; amount_cents: number; policy: CapitalPolicy; basis: { source: { account_code: string; date: string }; provider: { company_name?: string; first_name?: string | null; last_name?: string | null } | null; asset: { valuation_cents: number } | null } }
export interface CapitalEvent { id: number; operation: 'release' | 'reverse'; active: boolean; entry_id: number; amount_cents: number; receipt: { entry: { period_id: number; entry_date: string }; lines: Array<{ account_code: string; side: string; amount: string }> } }
export interface CapitalOverview { sources: Array<{ source: CapitalSource; used_cents: number; available_cents: number; events: CapitalEvent[] }> }
export interface CapitalChoices { period: Pick<AccountingPeriod, 'id' | 'starts_on' | 'ends_on' | 'status'>; sources: Array<{ id: number; account_code: string; date: string; amount_cents: number; description: string | null }>; costs: CapitalChoices['sources']; providers: Array<{ id: number; label: string }>; assets: Array<{ id: number; label: string }>; activities: Array<{ id: number; label: string }>; tax_regimes: Array<{ id: number; label: string }>; accounts: Array<{ code: string; label: string }>; sources_more: boolean; costs_more: boolean; providers_more: boolean; assets_more: boolean }
export type CapitalOperation = { operation: 'register'; policy: CapitalPolicy } | { operation: 'fund'; source_id: number; cost_line_id: number; amount_cents: number; date: string } | { operation: 'reverse'; event_id: number; date: string }
export interface CapitalPreview { input_hash: string; financial_authorization: true; policy?: CapitalPolicy; basis?: { source: { amount_cents: number }; asset: { valuation_cents: number } | null }; plan?: { journal: { header: { entry_date: string }; lines: Array<{ account_code: string; side: string; amount: string }> }; source_used_cents: number; cost_capacity: { available_cents: number } | null } }
export type CapitalConfirmation = CapitalOperation & { input_hash: string; reason: string; idempotency_key: string }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const capitalApi = {
 read: (supplier: number) => api.get<CapitalOverview>('/accounting/nonprofit-capital', options(supplier)).then(r => r.data),
 periods: (supplier: number) => api.get<AccountingPeriod[]>('/accounting/periods', options(supplier)).then(r => r.data),
 choices: (supplier: number, period: number, search: Record<string, string> = {}) => api.get<CapitalChoices>(`/accounting/periods/${period}/nonprofit-capital/choices`, { ...options(supplier), params: search }).then(r => r.data),
 preview: (supplier: number, body: CapitalOperation) => api.post<CapitalPreview>('/accounting/nonprofit-capital/preview', body, options(supplier)).then(r => r.data),
 confirm: (supplier: number, body: CapitalConfirmation) => api.post<CapitalSource | CapitalEvent>('/accounting/nonprofit-capital/confirm', body, options(supplier)).then(r => r.data),
}

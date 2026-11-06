import { api } from './client'
export interface ConversionPeriod { id: number; fiscal_year: number; starts_on: string; ends_on: string; status: string }
export interface AccountDefinition { name: string; normal_side: 'debit' | 'credit' | null; tax_deductibility: 'deductible' | 'non_deductible'; is_active: number }
export interface ConversionDefinition { account_code: string; account_type: string; parent_code: string | null; definition: AccountDefinition; existing: boolean }
export interface ConversionCell { cell_key: string; account_code: string; account_type: string; role: 'balance' | 'retained' | 'pending_approval'; czk_cents: number; foreign_cents: number; currency_code: string; dimensions: Record<string, number>; source_line_ids: number[] }
export interface ConversionMapping { account_code: string; activity: string; activity_kind: { code: string; name: string }; tax_regime: string }
export interface ConversionChoices { source: ConversionPeriod; target: ConversionPeriod; cells: ConversionCell[]; source_labels: Record<string, { cost_center: string | null; project: string | null; dimensions: string[] }>; definitions: ConversionDefinition[]; before_definitions: Record<string, AccountDefinition> }
export interface ConversionCommand { source_period_id: number; target_period_id: number; evidence: string; definitions: ConversionDefinition[]; mapping: Record<string, ConversionMapping> }
export interface ConversionLine { account_code: string; side: 'debit' | 'credit'; amount: string | number; currency_code?: string | null; amount_foreign?: string | null }
export interface ConversionReview { state_hash: string; preview_hash: string; plan: { lines: ConversionLine[] } }
export interface ConversionRecord { id: number; source_period_id: number; target_period_id: number; effective_from: string; status: string; opening_entry_id: number | null }
export interface ConversionPage { periods: ConversionPeriod[]; rows: ConversionRecord[]; financial_enabled: boolean; can_write: boolean }
export interface CancellationReview { conversion_id: number; receipt_hash: string; preview_hash: string }
export interface DecisionCell { key: string; account_code: string; available_cents: number; dimensions: Record<string, number> }
export interface DecisionChoices { cells: DecisionCell[]; target_roles: { role: string; account_code: string }[] }
export interface DecisionCommand { decision_date: string; decision_authority: string; decision_reference: string; allocations: { cell_key: string; role: string; amount: string }[] }
const base = '/accounting/framework-conversions'
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const frameworkConversionApi = {
 list: async (supplier: number) => (await api.get<ConversionPage>(base, options(supplier))).data,
 choices: async (supplier: number, source: number, target: number) => (await api.get<ConversionChoices>(`${base}/choices`, { ...options(supplier), params: { source_period_id: source, target_period_id: target } })).data,
 preview: async (supplier: number, command: ConversionCommand) => (await api.post<ConversionReview>(`${base}/preview`, { command }, options(supplier))).data,
 confirm: async (supplier: number, command: ConversionCommand, review: ConversionReview, token: string) => (await api.post<{ conversion_id: number; opening_entry_id: number }>(`${base}/commands`, { command, state_hash: review.state_hash, preview_hash: review.preview_hash, command_token: token }, options(supplier))).data,
 cancellationPreview: async (supplier: number, id: number) => (await api.post<CancellationReview>(`${base}/${id}/cancellation-preview`, {}, options(supplier))).data,
 cancel: async (supplier: number, id: number, review: CancellationReview, token: string) => (await api.post(`${base}/${id}/cancellations`, { receipt_hash: review.receipt_hash, preview_hash: review.preview_hash, command_token: token }, options(supplier))).data,
 decisionChoices: async (supplier: number, id: number, date: string) => (await api.get<DecisionChoices>(`${base}/${id}/result-decision-choices`, { ...options(supplier), params: { date } })).data,
 decisionPreview: async (supplier: number, id: number, command: DecisionCommand) => (await api.post<ConversionReview>(`${base}/${id}/result-decision-preview`, { command }, options(supplier))).data,
 decide: async (supplier: number, id: number, command: DecisionCommand, review: ConversionReview, token: string) => (await api.post(`${base}/${id}/result-decisions`, { command, state_hash: review.state_hash, preview_hash: review.preview_hash, command_token: token }, options(supplier))).data,
}

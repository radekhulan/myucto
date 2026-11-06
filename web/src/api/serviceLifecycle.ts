import { api } from './client'
export type ServicePolicy = 'service_balance_sheet' | 'service_profit_and_loss'
export type LifecycleOperation = 'start' | 'start_opening' | 'unwind' | 'finalize'
export interface LifecycleScope { unit_id: number; service_id: number; period_from: string; period_to: string; policy: ServicePolicy; evidence: string; service_cost_account: string }
export type LifecycleCommand = (LifecycleScope & ({ operation: 'start'; entry_ids: number[]; coverage_title: string } | { operation: 'start_opening'; opening_item_id: number })) | { operation: 'unwind'; case_id: number; accounting_date: string } | { operation: 'finalize'; case_id: number; accounting_date: string; invoice_id: number; line_id: number; title: string }
export interface LifecycleCapabilities { manage: boolean; post: boolean; reverse: boolean; unwind: boolean }
export interface ServicePolicyVersion { id: number; service_id: number; service_label: string; valid_from: string; policy: ServicePolicy; evidence: string; origin: 'declared' | 'first_case' | 'migrated'; recorded_at: string }
export interface LifecycleCard { id: number; unit_id: number; unit_label: string; service_id: number; service_label: string; period_from: string; period_to: string; policy: ServicePolicy }
export interface LifecycleDetail { id: number; case: LifecycleScope; phase: 'estimated' | 'unwound' | 'finalized'; estimate_cents: string; final_cents: string | null; cost_line_id: number | null; events: Array<{ event_key: string; journal_entry_id: number | null; recorded_at: string }>; capabilities: LifecycleCapabilities }
export interface LifecycleSource { id: number; label: string; invoice_id?: number; lines?: Array<{ id: number; account_code: string; side: 'debit' | 'credit'; amount: string }> }
export interface LifecyclePlan { operation: LifecycleOperation; policy: ServicePolicy; period_from: string; period_to: string; estimate_cents: string; final_cents: string | null; source_fingerprint: string; journals: Array<{ accounting_date: string; lines: Array<{ account_code: string; side: 'debit' | 'credit'; amount: string }> }> }
export interface LifecyclePreview { expected_state: string; preview_hash: string; plan: LifecyclePlan }
export interface LifecycleResult { case_id: number; operation: LifecycleOperation; plan: LifecyclePlan }
export interface LifecycleChoices { as_of: string; units: Array<{ id: number; label: string }>; services: Array<{ id: number; label: string }>; accounts: Array<{ id: number; code: string; label: string }> }
const path = '/property/service-lifecycles'
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const serviceLifecycleApi = {
 list: (supplier: number, after_id = 0) => api.get<{ items: LifecycleCard[]; next_cursor: number | null; capabilities: LifecycleCapabilities }>(path, { ...options(supplier), params: { after_id } }).then(r => r.data),
 detail: (supplier: number, id: number) => api.get<LifecycleDetail>(`${path}/${id}`, options(supplier)).then(r => r.data),
 choices: (supplier: number, date: string) => api.get<LifecycleChoices>(`${path}/choices`, { ...options(supplier), params: { date } }).then(r => r.data),
 sources: (supplier: number, kind: 'estimates' | 'invoices' | 'openings', query: { unit_id: number; service_id: number; from: string; to: string; after_id?: number }) => api.get<{ items: LifecycleSource[]; next_cursor: number | null }>(`${path}/sources/${kind}`, { ...options(supplier), params: query }).then(r => r.data),
 preview: (supplier: number, command: LifecycleCommand) => api.post<LifecyclePreview>(`${path}/preview/${command.operation}`, { command }, options(supplier)).then(r => r.data),
 policies: (supplier: number, service_id?: number, after_id = 0) => api.get<{ items: ServicePolicyVersion[]; next_cursor: number | null; capabilities: LifecycleCapabilities }>(`${path}/policies`, { ...options(supplier), params: { ...(service_id ? { service_id } : {}), after_id } }).then(r => r.data),
 declarePolicy: (supplier: number, body: { service_id: number; valid_from: string; policy: ServicePolicy; evidence: string }) => api.post<ServicePolicyVersion>(`${path}/policies`, body, options(supplier)).then(r => r.data),
 execute: (supplier: number, command: LifecycleCommand, preview: LifecyclePreview, idempotency_key: string) => api.post<LifecycleResult>(`${path}/commands/${command.operation}`, { command, expected_state: preview.expected_state, preview_hash: preview.preview_hash, idempotency_key }, options(supplier)).then(r => r.data),
}

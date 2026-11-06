import { api } from './client'
export interface OpeningItem { case_ref: string; occurrence_ref: string; kind: 'debt' | 'credit' | 'advance' | 'service_carry'; unit_id: number; service_id: number | null; components: { key: string; role: string; account_code: string; amount_cents: string; source_line_id?: number | null }[]; [key: string]: unknown }
export interface OpeningRequest { mode: 'post_new' | 'bind_existing' | 'revision' | 'reversal'; accounting_date: string; effective_at: string; expected_revision: number; journal_entry_id?: number | null; journal_version?: number | null; evidence_ref: string; reason: string; items: OpeningItem[]; other_balances: Record<string, unknown>[] }
export interface OpeningBatch { id: number; revision_no: number; status: string; mode: string; accounting_date: string; effective_at: string; recorded_at: string; journal_entry_id: number; request: OpeningRequest; items: { id: number; kind: string; unit_id: number; service_id: number | null; amount_cents: string }[] }
export interface OpeningBindCandidate { journal_entry_id: number; journal_version: number; document_no: string | null; entry_date: string; description: string | null }
export interface OpeningPreview { preview_hash: string; snapshot: { request: OpeningRequest; items: OpeningItem[]; journal: { lines: { account_code: string; side: string; amount: string }[] } | null; reversal: { lines: { account_code: string; side: string; amount: string }[] } | null } }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const propertyOpeningApi = {
 list: (supplier: number) => api.get<{ items: OpeningBatch[]; bind_candidates: OpeningBindCandidate[]; capabilities: { manage: boolean; post: boolean; revise: boolean } }>('/property/openings', options(supplier)).then(r => r.data),
 preview: (supplier: number, request: OpeningRequest) => api.post<OpeningPreview>('/property/openings/preview', request, options(supplier)).then(r => r.data),
 confirm: (supplier: number, request: OpeningRequest, hash: string, token: string) => api.post<OpeningBatch>(`/property/openings/${['revision', 'reversal'].includes(request.mode) ? 'revise' : 'confirm'}`, { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
}

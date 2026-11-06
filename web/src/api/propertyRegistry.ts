import { api } from './client'

export type PropertyKind = 'house' | 'unit' | 'service'
export type Fraction = { num: string; den: string }
export type Ownership = { group_key: string; kind: 'single' | 'coownership_share' | 'marital_community'; share: Fraction; members: number[]; title_ref: string }
export type PropertyRole = { client_id: number; role: 'payer' | 'representative' | 'delivery'; scopes: string[]; title_ref: string }
export type Identifier = { kind: 'vs' | 'ss' | 'account' | 'text' | 'sipo_link'; value: string; payment_scope: string; source: 'manual' | 'import'; title_ref: string }
export interface UnitState { unit_no: string; kind: string; active: boolean; attributes: Record<string, Fraction>; ownerships: Ownership[]; roles: PropertyRole[]; identifiers: Identifier[]; services: number[] }
export interface HouseState { name: string; active: boolean; address: { street: string; city: string; postal_code: string; country: string }; declaration_ref: string; share_scope: string; registry_complete: boolean }
export interface ServiceState { name: string; active: boolean; measure: string; measure_label: string }
export type PropertyState = UnitState | HouseState | ServiceState
export interface PropertyRevision { id: number; revision: number; status: 'pending' | 'confirmed'; effective_from: string; effective_to: string | null; recorded_at: string; state: PropertyState; reason: string; title_ref: string; resolves_revision_id: number | null; state_hash: string }
export interface PropertyObject { id: number; code: string; kind: PropertyKind; parent_id: number | null; current_revision: number; dimension_value_id: number | null; revision: PropertyRevision | null; pending_revision_ids: number[]; ownership_review_required?: boolean; as_of: string; history?: PropertyRevision[]; parties?: Array<{ id: number; company_name: string; first_name: string | null; last_name: string | null }> }
export interface PropertyProfile { id: number; revision_no: number; enabled: boolean; effective_from: string; effective_to: string | null; reason: string; title_ref: string }
export interface ProfileResponse { as_of: string; profile: PropertyProfile | null; history: PropertyProfile[]; capabilities: { manage: boolean; confirm: boolean; export: boolean }; import_columns: string[] }
export interface PropertyMutation { code?: string; parent_id?: number; expected_revision: number; effective_from: string; effective_to: string | null; status: 'pending' | 'confirmed'; state: PropertyState; reason: string; title_ref: string; idempotency_key: string }
export interface ImportUnit { code: string; state: UnitState; object_id: number | null; expected_revision: number }
export interface ImportPlan { house_id: number; house_revision: number; catalog_revisions: Record<number, number>; profile_revision: number; effective_from: string; effective_to: string | null; status: 'pending' | 'confirmed'; reason: string; title_ref: string; units: ImportUnit[] }
export interface ImportPreview { ready: boolean; units: Array<{ code: string; state: UnitState }>; unresolved_parties: Array<{ reference: string; name: string; email: string }>; unresolved_services: string[]; errors: Array<{ row?: number; code: string; message: string }>; plan: ImportPlan | null; preview_hash: string | null }
export interface RegistrySummary { as_of: string; scopes: Array<{ scope: string; houses: Array<{ id: number; name: string }>; units: number; ownership_review_required: number; total_share: Fraction; share_complete: boolean; declared_complete: boolean }> }

const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
const collection = (kind: PropertyKind) => ({ house: 'houses', unit: 'units', service: 'services' })[kind]
export const propertyRegistryApi = {
  profile: (supplier: number, asOf: string, knownAt?: string) => api.get<ProfileResponse>('/property/profile', { ...options(supplier), params: { as_of: asOf, known_at: knownAt || undefined } }).then(r => r.data),
  list: (supplier: number, kind: PropertyKind, asOf: string, knownAt?: string) => api.get<{ items: PropertyObject[] }>(`/property/${collection(kind)}`, { ...options(supplier), params: { as_of: asOf, known_at: knownAt || undefined } }).then(r => r.data.items),
  detail: (supplier: number, id: number, asOf: string, knownAt?: string) => api.get<PropertyObject>(`/property/objects/${id}`, { ...options(supplier), params: { as_of: asOf, known_at: knownAt || undefined } }).then(r => r.data),
  summary: (supplier: number, asOf: string, knownAt?: string) => api.get<RegistrySummary>('/property/summary', { ...options(supplier), params: { as_of: asOf, known_at: knownAt || undefined } }).then(r => r.data),
  profileSave: (supplier: number, input: { expected_revision: number; effective_from: string; effective_to: string | null; enabled: boolean; reason: string; title_ref: string; idempotency_key: string }) => api.post('/property/profile', input, options(supplier)).then(r => r.data),
  create: (supplier: number, kind: PropertyKind, input: PropertyMutation) => api.post<{ id: number; revision: number }>(`/property/${collection(kind)}`, input, options(supplier)).then(r => r.data),
  revise: (supplier: number, id: number, input: PropertyMutation) => api.post<{ id: number; revision: number }>(`/property/objects/${id}/revisions`, input, options(supplier)).then(r => r.data),
  confirm: (supplier: number, id: number, revisionId: number, input: { expected_revision: number; effective_from: string; reason: string; title_ref: string; idempotency_key: string }) => api.post(`/property/objects/${id}/revisions/${revisionId}/confirm`, input, options(supplier)).then(r => r.data),
  importPreview: (supplier: number, input: Record<string, unknown>) => api.post<ImportPreview>('/property/import/preview', input, options(supplier)).then(r => r.data),
  importApply: (supplier: number, plan: ImportPlan, hash: string, token: string) => api.post('/property/import/apply', { plan, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
  export: (supplier: number, asOf: string, knownAt?: string) => api.get('/property/export', { ...options(supplier), params: { as_of: asOf, known_at: knownAt || undefined } }).then(r => r.data),
}

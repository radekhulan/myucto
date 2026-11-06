import { api } from './client'
import type { Fraction } from './propertyRegistry'
export const chargeRounding = 'aggregate_half_up_largest_remainder_unit_id_v1' as const
export interface ChargeComponent { id: number; code: string; name: string; kind: string; service_id: number | null; account_code: string; effective_at: string }
export interface ChargeUnit { id: number; code: string; unit_no: string; attributes: Record<string, Fraction>; services: number[]; revision_id: number; state_hash: string }
export interface ChargeRegistry { as_of: string; house: { id: number; name: string; registry_complete?: boolean }; units: ChargeUnit[]; services: { id: number; name: string }[] }
export interface ChargeRate { component_id: number; unit_ids: number[]; method: string; mode: string; amount: string; rounding: typeof chargeRounding; legal_basis: string; scope_ref: string; correction: boolean; correction_reason: string }
export interface ChargeRequest { house_id: number; expected_revision: number; effective_from: string; effective_to: string | null; basis_at: string; basis_policy: 'fixed_registry_snapshot'; period_policy: 'no_automatic_proration'; frequency: string; decision_ref: string; decided_at: string; reason: string; rates: ChargeRate[] }
export interface ChargeCalculation { total_cents: string; unit_totals: { unit_id: number; amount_cents: string }[]; issuance_facts: { code: string; required: string[]; component_id?: number }[]; rates: { component: ChargeComponent; rate: ChargeRate; calculation: { total_cents: string; lines: { unit_id: number; basis: Fraction; exact_cents: Fraction; amount_cents: string; residual_cent: boolean }[] } }[] }
export interface ChargeDifference { unit_id: number; before_cents: string; after_cents: string; delta_cents: string }
export interface ChargePreview { request: ChargeRequest; registry: ChargeRegistry; calculation: ChargeCalculation; previous_version_id: number | null; previous_units: { id: number; unit_no: string }[]; overlapping_versions: { version_id: number; revision: number; decision_ref: string; from: string; to: string | null }[]; previous_frozen_differences: ChargeDifference[]; same_basis_differences: ChargeDifference[] | null; same_basis_unavailable: { code: string } | null }
export interface ChargePreviewResponse { preview: ChargePreview; preview_hash: string }
export interface ChargeVersion { id: number; revision_no: number; effective_from: string; effective_to: string | null; recorded_at: string; request: ChargeRequest; preview: ChargePreview; preview_hash: string }
const options = (supplier: number) => ({ headers: { 'X-Supplier-Id': String(supplier) } })
export const propertyChargesApi = {
  components: (supplier: number) => api.get<{ items: ChargeComponent[]; capabilities: { manage: boolean; confirm: boolean } }>('/property/charges/components', options(supplier)).then(r => r.data),
  createComponent: (supplier: number, input: Record<string, unknown>) => api.post<ChargeComponent>('/property/charges/components', input, options(supplier)).then(r => r.data),
  context: (supplier: number, house: number, at: string) => api.get<ChargeRegistry>('/property/charges/context', { ...options(supplier), params: { house_id: house, as_of: at } }).then(r => r.data),
  versions: (supplier: number, house: number, at: string) => api.get<{ items: ChargeVersion[]; selected: ChargeVersion | null }>('/property/charges/versions', { ...options(supplier), params: { house_id: house, as_of: at } }).then(r => r.data),
  preview: (supplier: number, request: ChargeRequest) => api.post<ChargePreviewResponse>('/property/charges/preview', request, options(supplier)).then(r => r.data),
  save: (supplier: number, request: ChargeRequest, hash: string, token: string) => api.post<ChargeVersion>('/property/charges/versions', { request, preview_hash: hash, idempotency_key: token }, options(supplier)).then(r => r.data),
}

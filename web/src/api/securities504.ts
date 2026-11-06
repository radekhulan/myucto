import type { Fx504Map, Fx504CaseOrigin } from './fx504'

export interface SecuritiesCategoryFacts {
  period_id: number; starts_on: string; ends_on: string; assets_net: string; net_turnover: string; average_employees: string
  statement_reference: string; employees_reference: string
}
export interface SecuritiesProfile {
  scope: string; category: string; micro_exception: string; as_of: string; reference: string; scope_reference: string
  inception_reference: string; first_period_start: string; category_facts: SecuritiesCategoryFacts[]
  registration_reference?: string; registration_from?: string; registration_to?: string | null
}
export interface SecuritiesValuation {
  instrument: { kind: string; identity: string; classification: string; method: string; reference: string; classification_reference: string; designated_on: string; influence?: string; influence_reference?: string; debt_type?: string }
  as_of: string; evidence: Record<string, string>; target_czk: string
  members: { source_cell_hash: string; book_czk: string; book_foreign: string }[]
  posted_proof: { entry_id: number; asset_line_id: number; counterpart_line_id: number; component: string; amount_czk: string }[]
}
export interface SecuritiesMonetaryMember { source_cell_hash: string; book_czk: string; book_foreign: string; nature: 'monetary'; contract_reference: string; case_origin: Fx504CaseOrigin }
export interface SecuritiesEvidence { profile: SecuritiesProfile; valuations: SecuritiesValuation[]; inventory_account_ids?: number[]; non_security_members?: SecuritiesMonetaryMember[] }
export interface SecuritiesInventoryCell {
  case_origins?: Fx504CaseOrigin[]; cell_key: string; source_hash: string; line_id: number; entry_id: number; entry_date: string; account_id: number; account_code: string
  currency_code: string; dimensions: Fx504Map<number>; cost_center: string | null; project_id: number | null; book_czk: string; book_foreign: string
}
export interface SecuritiesAssessment {
  legal_basis: { valuation_rules: string; category_rules: string; period_start_from: string; period_end_from: string; period_end_to: string }
  available_accounts: { id: number; account_code: string; account_type: string }[]; monetary_members: SecuritiesMonetaryMember[]
  complete: boolean; as_of: string; inventory_hash: string; evidence: SecuritiesEvidence | null
  findings: { code: string; valuation_index?: number | null; entry_id?: number }[]
  cells: SecuritiesInventoryCell[]; opening_entry_ids: number[]
  profile_periods: { id: number; starts_on: string; ends_on: string; fiscal_year: number }[]
  profile_history: { period_id: number; assets_net: string; net_turnover: string; avg_employees: number; raw_category: string }[]
  posted_entries: { id: number; document_no: string | null; entry_date: string; lines: { id: number; account_code: string; signed_czk: string; currency_code: string; dimensions: Fx504Map<number>; cost_center: string | null; project_id: number | null }[] }[]
  valuations: { identity: string; method: string; book_czk: string; target_czk: string; change_czk: string; components: { kind: string; route: string; amount_czk: string }[] }[]
}

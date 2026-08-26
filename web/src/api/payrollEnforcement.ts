import { api } from './client'

export type EnforcementCaseKind = 'enforcement' | 'voluntary_agreement'
export type EnforcementCaseStatus =
  | 'received'
  | 'withhold_and_hold'
  | 'remit'
  | 'deferred_no_withholding'
  | 'deferred_hold'
  | 'paid'
  | 'stopped'
export type EnforcementCaseCommand =
  | 'mark_final'
  | 'authorize_remittance'
  | 'defer_no_withholding'
  | 'defer_hold'
  | 'resume_holding'
  | 'resume_remittance'
  | 'mark_paid'
  | 'stop'
export type EnforcementClaimCategory =
  | 'current_maintenance'
  | 'maintenance_arrears'
  | 'substitute_maintenance'
  | 'other_priority'
  | 'non_priority'
export const pensionEvidenceValues = ['unknown', 'none', 'verified'] as const
export type PensionEvidenceValue = typeof pensionEvidenceValues[number]

/**
 * Proč měsíční exekuční evidence v daném měsíci platí — nebo proč se
 * nevyžadovala. Zrcadlí PHP enum `EnforcementEvidenceSource`:
 *
 *  • `declared` — příznak je v evidenci zapnutý;
 *  • `missing` — doložit bylo co a nikdo nic nedoložil (blokuje běh);
 *  • `not_applicable` — v tomto měsíci nebylo co dokládat;
 *  • `nothing_withheld` — nárok se uplatňuje, ale tento měsíc se nic nesráží.
 *    Kvůli exekuci se doklad neptá, jenže strop dobrovolné dohody o srážkách
 *    se podle § 148 odst. 2 zákoníku práce odvozuje z TÉŽE nezabavitelné
 *    částky, takže nedoložený nárok uzavře kapacitu dohod na nulu.
 */
export const enforcementEvidenceSources = [
  'declared',
  'missing',
  'not_applicable',
  'nothing_withheld',
] as const
export type EnforcementEvidenceSourceValue = typeof enforcementEvidenceSources[number]

export interface EnforcementEvidenceScope {
  claim_register: EnforcementEvidenceSourceValue
  dependants: EnforcementEvidenceSourceValue
  spouse: EnforcementEvidenceSourceValue
}

export interface EnforcementClaim {
  id: number
  case_id: number
  legal_basis: 'statutory' | 'voluntary_agreement'
  category: EnforcementClaimCategory
  outstanding_minor_units: number
  maintenance_weight_minor_units: number | null
  priority_date: string | null
  order_issued_on: string | null
  legal_title_verified: boolean
  order_or_notice_delivered: boolean
  priority_classification_verified: boolean
  agreement_verified: boolean
  due_monetary_claim_verified: boolean
  is_active: boolean
  row_version: number
  case_row_version?: number
}

export interface EnforcementEvent {
  id: number
  command_name: EnforcementCaseCommand
  from_status: EnforcementCaseStatus | null
  to_status: EnforcementCaseStatus
  reason: string | null
  decision_document_id?: number | null
  actor_user_id: number | null
  created_at: string
}

export interface EnforcementLedgerEntry {
  id: number
  claim_id: number | null
  month_result_id: number
  entry_kind: 'withheld' | 'held' | 'remitted' | 'released_to_employee' | 'employer_fee' | 'adjustment'
  amount_minor_units: number
  actor_user_id: number | null
  created_at: string
}

export interface EnforcementCaseSummary {
  id: number
  employee_id: number
  full_name: string
  case_kind: EnforcementCaseKind
  status: EnforcementCaseStatus
  effective_from: string
  effective_to: string | null
  evidence_complete: boolean
  recipient_verified: boolean
  row_version: number
  claim_count: number
  outstanding_minor_units: number
  created_at: string
  updated_at: string
}

export interface EnforcementCasesPage {
  cases: EnforcementCaseSummary[]
  total: number
  limit: number
  offset: number
}

export interface EnforcementSettlementClaim {
  claim_id: number
  category: EnforcementClaimCategory
  priority_date: string | null
  is_active: boolean
  outstanding_minor: number
  withheld_minor: number
  held_minor: number
  liability_minor: number
  settled_minor: number
  remaining_minor: number
}

export interface EnforcementSettlement {
  claims: EnforcementSettlementClaim[]
  withheld_minor: number
  held_minor: number
  liability_minor: number
  settled_minor: number
  outstanding_minor: number
  remaining_minor: number
}

export interface EnforcementCaseDetail extends EnforcementCaseSummary {
  recipient_institution_id: number | null
  claims: EnforcementClaim[]
  events: EnforcementEvent[]
  ledger: EnforcementLedgerEntry[]
  settlement: EnforcementSettlement
}

export interface EnforcementClaimPayload {
  legal_basis: 'statutory' | 'voluntary_agreement'
  category: EnforcementClaimCategory
  outstanding_minor_units: number
  maintenance_weight_minor_units: number | null
  priority_date: string | null
  order_issued_on: string | null
  legal_title_verified: boolean
  order_or_notice_delivered: boolean
  priority_classification_verified: boolean
  agreement_verified: boolean
  due_monetary_claim_verified: boolean
  same_order_as_claim_id?: number | null
}

export interface EnforcementMonthEvidence {
  id: number | null
  employee_id: number
  period_start: string
  claim_register_evidence_complete: boolean
  dependants_evidence_complete: boolean
  spouse_evidence_complete: boolean
  pension_evidence: PensionEvidenceValue
  has_multiple_payers: boolean
  protected_amount_override_minor_units: number | null
  protected_amount_override_verified: boolean
  insolvency_mode: 'none' | 'alert_only' | 'approved_standard' | 'court_determined_amount'
  insolvency_decision_verified: boolean
  insolvency_recipient_verified: boolean
  insolvency_payment_instruction_id: number | null
  insolvency_employment_id: number | null
  insolvency_institution_account_id: number | null
  insolvency_decision_document_id: number | null
  insolvency_payment_instruction_hash: string | null
  court_determined_amount_minor_units: number | null
  row_version: number | null
}

export interface InsolvencyEmploymentOption {
  id: number
  code: string
  relation_type: string
  status: 'active' | 'ended'
  start_date: string | null
  actual_start_date: string | null
  end_date: string | null
}

export interface InsolvencyRecipientAccountOption {
  id: number
  institution_id: number
  institution_code: string
  institution_name: string
  bank_account_masked: string
  currency_code: 'CZK'
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_from: string
  valid_to: string | null
  source_kind: string
  source_reference: string
  verified_on: string
  row_version: number
}

export interface InsolvencyOptions {
  employments: InsolvencyEmploymentOption[]
  recipient_accounts: InsolvencyRecipientAccountOption[]
}

export interface EnforcementDependant {
  id: number
  employee_id: number
  dependant_kind: 'dependant' | 'spouse_partner'
  valid_from: string
  valid_to: string | null
  eligibility_verified: boolean
  excluded_for_maintenance: boolean
  row_version: number
}

export const payrollEnforcementApi = {
  /**
   * Stránka seznamu případů. Filtr i stránkování drží server — bez `limit` se
   * neposílá „všechno", ale serverový strop, a o zbytku by výpis mlčel.
   */
  casesPage: (params?: {
    employee_id?: number
    status?: EnforcementCaseStatus
    limit?: number
    offset?: number
  }) =>
    api.get<EnforcementCasesPage>('/payroll/enforcement/cases', { params })
      .then(response => response.data),
  detail: (id: number) =>
    api.get<{ case: EnforcementCaseDetail }>(`/payroll/enforcement/cases/${id}`)
      .then(response => response.data.case),
  create: (payload: {
    employee_id: number
    case_kind: EnforcementCaseKind
    effective_from: string
  }) =>
    api.post<{ case: EnforcementCaseDetail }>('/payroll/enforcement/cases', payload)
      .then(response => response.data.case),
  deleteCase: (caseId: number, rowVersion: number) =>
    api.delete<{ deleted: true; id: number }>(`/payroll/enforcement/cases/${caseId}`, {
      data: { row_version: rowVersion },
    }).then(response => response.data),
  addClaim: (caseId: number, payload: EnforcementClaimPayload) =>
    api.post<{ claim: EnforcementClaim }>(
      `/payroll/enforcement/cases/${caseId}/claims`,
      payload,
    ).then(response => response.data.claim),
  updateClaim: (
    caseId: number,
    claimId: number,
    payload: EnforcementClaimPayload & { row_version: number },
  ) =>
    api.put<{ claim: EnforcementClaim }>(
      `/payroll/enforcement/cases/${caseId}/claims/${claimId}`,
      payload,
    ).then(response => response.data.claim),
  deleteClaim: (caseId: number, claimId: number, rowVersion: number) =>
    api.delete<{
      deleted: true
      id: number
      case_id: number
      case_row_version: number
    }>(`/payroll/enforcement/cases/${caseId}/claims/${claimId}`, {
      data: { row_version: rowVersion },
    }).then(response => response.data),
  updateEvidence: (
    caseId: number,
    payload: {
      evidence_complete: boolean
      recipient_verified: boolean
      row_version: number
      recipient_institution_id?: number | null
    },
  ) =>
    api.put<{ case: EnforcementCaseDetail }>(
      `/payroll/enforcement/cases/${caseId}/evidence`,
      payload,
    ).then(response => response.data.case),
  transition: (
    caseId: number,
    command: EnforcementCaseCommand,
    payload: {
      row_version: number
      reason?: string | null
      decision_document_id?: number | null
    },
  ) =>
    api.post<{ case: EnforcementCaseDetail }>(
      `/payroll/enforcement/cases/${caseId}/commands/${command}`,
      payload,
    ).then(response => response.data.case),
  monthEvidence: (employeeId: number, period: string) =>
    api.get<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/enforcement/people/${employeeId}/month/${period}/evidence`,
    ).then(response => response.data.evidence),
  saveMonthEvidence: (
    employeeId: number,
    period: string,
    payload: Omit<EnforcementMonthEvidence, 'id' | 'employee_id' | 'period_start'>,
  ) =>
    api.put<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/enforcement/people/${employeeId}/month/${period}/evidence`,
      payload,
    ).then(response => response.data.evidence),
  insolvencyOptions: (employeeId: number, period: string) =>
    api.get<InsolvencyOptions>(
      `/payroll/insolvency/people/${employeeId}/month/${period}/options`,
    ).then(response => response.data),
  insolvencyEvidence: (employeeId: number, period: string) =>
    api.get<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/insolvency/people/${employeeId}/month/${period}/evidence`,
    ).then(response => response.data.evidence),
  saveInsolvencyEvidence: (
    employeeId: number,
    period: string,
    payload: Omit<EnforcementMonthEvidence, 'id' | 'employee_id' | 'period_start'>,
  ) =>
    api.put<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/insolvency/people/${employeeId}/month/${period}/evidence`,
      payload,
    ).then(response => response.data.evidence),
  cancelInsolvency: (employeeId: number, period: string, rowVersion: number) =>
    api.post<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/insolvency/people/${employeeId}/month/${period}/commands/cancel`,
      { row_version: rowVersion },
    ).then(response => response.data.evidence),
  dependants: (employeeId: number) =>
    api.get<{ dependants: EnforcementDependant[] }>(
      `/payroll/enforcement/people/${employeeId}/dependants`,
    ).then(response => response.data.dependants),
  addDependant: (
    employeeId: number,
    payload: {
      dependant_kind: 'dependant' | 'spouse_partner'
      valid_from: string
      valid_to: string | null
      eligibility_verified: boolean
      excluded_for_maintenance: boolean
    },
  ) =>
    api.post<{ dependant: EnforcementDependant }>(
      `/payroll/enforcement/people/${employeeId}/dependants`,
      payload,
    ).then(response => response.data.dependant),
}

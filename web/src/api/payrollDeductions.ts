import { api } from './client'
import type { EnforcementEvidenceScope } from './payrollEnforcement'

export type DeductionAgreementKind =
  | 'advance'
  | 'meal'
  | 'contribution'
  | 'damage'
  | 'other'

export type DeductionAgreementStatus =
  | 'draft'
  | 'active'
  | 'paused'
  | 'ended'
  | 'cancelled'

export type DeductionAgreementCommand =
  | 'activate'
  | 'pause'
  | 'resume'
  | 'end'
  | 'cancel'

export const deductionAgreementKinds: DeductionAgreementKind[] = [
  'advance', 'meal', 'contribution', 'damage', 'other',
]

/** Pásmo 1–9 patří zákonným a exekučním srážkám (backend to vynucuje). */
export const deductionPriorityFloor = 10
export const deductionPriorityCeiling = 9999

export interface DeductionAgreementSummary {
  id: number
  employee_id: number
  full_name: string
  agreement_reference: string
  title: string
  deduction_kind: DeductionAgreementKind
  status: DeductionAgreementStatus
  priority_no: number
  requested_minor: number
  basis_points: number | null
  basis_amount_minor: number | null
  total_limit_minor: number | null
  withheld_total_minor: number
  remaining_limit_minor: number | null
  valid_from: string
  valid_to: string | null
  /**
   * Den doručení dohody plátci mzdy (§ 2045 odst. 2 obč. zák.). Určuje POŘADÍ
   * dohody vůči exekucím podle § 280 odst. 5 o. s. ř. `null` = dohoda
   * zaevidovaná dřív, než se datum ukládalo; taková se řadí až za exekuce.
   */
  delivered_on: string | null
  recipient_reference: string | null
  note: string | null
  row_version: number
  version_no: number
  enters_payroll_run: boolean
  created_at: string
  updated_at: string
}

export interface DeductionAgreementsPage {
  agreements: DeductionAgreementSummary[]
  total: number
  limit: number
  offset: number
}

export interface DeductionAgreementVersion {
  id: number
  version_no: number
  change_kind: 'created' | 'updated' | 'activated' | 'paused' | 'resumed' | 'ended' | 'cancelled'
  title: string
  deduction_kind: DeductionAgreementKind
  status: DeductionAgreementStatus
  priority_no: number
  requested_minor: number
  basis_points: number | null
  basis_amount_minor: number | null
  total_limit_minor: number | null
  withheld_total_minor: number
  valid_from: string
  valid_to: string | null
  delivered_on: string | null
  effective_from: string
  reason: string | null
  created_at: string
}

export interface DeductionAgreementLedgerEntry {
  id: number
  revision_id: number
  event_kind: 'withheld' | 'reversed' | 'paid' | 'payment_reversed'
  amount_minor: number
  source_ledger_id: number | null
  created_at: string
}

export interface DeductionAgreementDetail extends DeductionAgreementSummary {
  versions: DeductionAgreementVersion[]
  ledger: DeductionAgreementLedgerEntry[]
}

export interface DeductionAgreementPayload {
  agreement_reference?: string | null
  title: string
  deduction_kind: DeductionAgreementKind
  priority_no: number
  requested_minor?: number
  basis_points?: number | null
  basis_amount_minor?: number | null
  total_limit_minor?: number | null
  valid_from: string
  valid_to?: string | null
  delivered_on?: string | null
  recipient_reference?: string | null
  note?: string | null
}

export interface NetResultDeduction {
  agreement_id: number | null
  deduction_reference: string
  agreement_reference: string | null
  title: string
  deduction_kind: DeductionAgreementKind
  total_limit_minor: number | null
  priority_no: number
  requested_minor: number
  applied_minor: number
  /** Provedla se dohoda v tomhle měsíci? Pozastavená se neprovádí. */
  active: boolean
  /**
   * Kolik se REÁLNĚ nedostalo věřiteli. U pozastavené dohody 0 — nesrazilo se
   * nic proto, že se srážet nemělo, ne proto, že by na to nezbylo místo.
   */
  unapplied_minor: number
  /**
   * Účetní zbytek `requested − applied`, na kterém stojí invariant zmrazeného
   * snímku. U pozastavené dohody se rovná celé nárokované částce, takže se
   * uživateli nezobrazuje jako schodek — je to jen doprovodné číslo.
   */
  accounting_unapplied_minor: number
}

export interface NetResultAllocation {
  allocation_order: number
  allocation_reference: string
  allocation_kind: 'fixed' | 'percentage' | 'remainder'
  destination_kind: 'bank' | 'cash'
  destination_label: string | null
  /** Jen maska účtu — backend plaintext ani otisk nevydává. */
  destination_masked: string | null
  payout_account_id: number | null
  amount_minor: number
}

export interface NetResultBreakdown {
  revision: {
    id: number
    run_id: number
    revision_no: number
    revision_kind: string
    status: string
  }
  person: { employee_id: number; full_name: string }
  income: {
    cash_minor: number
    non_cash_minor: number
    gross_minor: number
    relationships: {
      relationship_reference: string
      cash_minor: number
      non_cash_minor: number
    }[]
  }
  contributions: { employee_social_minor: number; employee_health_minor: number }
  tax: { advance_minor: number; withholding_minor: number; bonus_minor: number }
  correction_minor: number
  net_before_deductions_minor: number
  deductions: NetResultDeduction[]
  deducted_minor: number
  net_payable_minor: number
  enforcement_withheld_minor: number
  /**
   * Rozsah exekuční evidence ze zmrazené revize. `null` = revize spočtená
   * dřív, než se rozsah začal ukládat; nedopočítává se, takže obrazovka
   * o důvodu mlčí, místo aby si nějaký domyslela.
   */
  enforcement_evidence_source: EnforcementEvidenceScope | null
  payable_after_enforcement_minor: number
  allocation_status: 'resolved' | 'no_rules'
  allocations: NetResultAllocation[]
  allocations_total_minor: number
}

export const payrollDeductionsApi = {
  /**
   * Stránka seznamu srážek. Filtr i stránkování drží server — bez `limit` se
   * neposílá „všechno", ale serverový strop, a o zbytku by výpis mlčel.
   */
  agreementsPage: (params?: {
    employee_id?: number
    status?: DeductionAgreementStatus
    limit?: number
    offset?: number
  }) =>
    api.get<DeductionAgreementsPage>('/payroll/deduction-agreements', { params })
      .then(response => response.data),
  agreement: (id: number) =>
    api.get<{ agreement: DeductionAgreementDetail }>(`/payroll/deduction-agreements/${id}`)
      .then(response => response.data.agreement),
  create: (payload: DeductionAgreementPayload & {
    employee_id: number
    status?: DeductionAgreementStatus
  }) =>
    api.post<{ agreement: DeductionAgreementDetail }>('/payroll/deduction-agreements', payload)
      .then(response => response.data.agreement),
  update: (
    id: number,
    payload: DeductionAgreementPayload & {
      row_version: number
      effective_from?: string | null
      reason?: string | null
    },
  ) =>
    api.put<{ agreement: DeductionAgreementDetail }>(
      `/payroll/deduction-agreements/${id}`,
      payload,
    ).then(response => response.data.agreement),
  transition: (
    id: number,
    command: DeductionAgreementCommand,
    payload: { row_version: number; effective_on?: string | null; reason?: string | null },
  ) =>
    api.post<{ agreement: DeductionAgreementDetail }>(
      `/payroll/deduction-agreements/${id}/commands/${command}`,
      payload,
    ).then(response => response.data.agreement),
  netResult: (revisionId: number, employeeId: number) =>
    api.get<{ net_result: NetResultBreakdown }>(
      `/payroll/revisions/${revisionId}/net-results/${employeeId}`,
    ).then(response => response.data.net_result),
}

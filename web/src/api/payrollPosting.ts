import { api } from './client'

// MZ-18-W07 — reconciliation účetního můstku mezd (mzda ↔ deník ↔ platby).
// Read-only report; žádné mutační metody.

export type PayrollPostingReconciliationCategoryKey =
  | 'gross_wages'
  | 'employer_contributions'
  | 'social_health_insurance'
  | 'income_tax'
  | 'other_deductions'
  | 'enforcement'
  | 'net_wage'
  | 'risky_savings'
  // Zápočet čisté mzdy na účet společníka (365). POROVNÁVANÁ kategorie —
  // mzdovou i deníkovou stranu má, platební ne (zápočtem se nic nevyplácí).
  | 'partner_settlement'
  // Informativní kategorie: deník i platby má vždy `null`, status
  // `not_applicable`, rozdíl z ní nikdy nevznikne. Nepeněžní plnění bez
  // účetního dopadu se navíc nezapočítává do porovnávané hrubé mzdy.
  | 'non_monetary_neutral'
  // Pohledávka za FÚ z převisu daňových bonusů nad odvodem záloh
  // (§ 35d odst. 5) — jen mzdová strana.
  | 'tax_bonus_receivable'
  // Závazky, které se platí, ale v mzdovém můstku se neúčtují (zákonné
  // pojištění odpovědnosti, benefity) — jen platební strana.
  | 'unposted_liabilities'

export type PayrollPostingReconciliationCategoryStatus = 'match' | 'diff' | 'not_applicable'

/** Kategorie, které se nikdy neporovnávají — jen se vykazují. */
export const PAYROLL_POSTING_INFORMATIONAL_CATEGORIES: readonly PayrollPostingReconciliationCategoryKey[] = [
  'non_monetary_neutral',
  'tax_bonus_receivable',
  'unposted_liabilities',
]

export interface PayrollPostingReconciliationCategory {
  key: PayrollPostingReconciliationCategoryKey
  /** `null` u kategorie, která mzdovou stranu z podstaty nemá. */
  payroll_minor: number | null
  journal_minor: number | null
  payments_liability_minor: number | null
  payments_paid_minor: number | null
  diff_payroll_journal_minor: number | null
  diff_payroll_payments_minor: number | null
  /** Třetí osa: deník ↔ platební závazek (schema v2). */
  diff_journal_payments_minor: number | null
  status: PayrollPostingReconciliationCategoryStatus
}

export interface PayrollPostingReconciliation {
  schema_version: string
  supplier_id: number
  period: string
  accounting_mode: 'double_entry' | 'tax_evidence'
  run: { id: number; status: string } | null
  revision: { id: number; revision_no: number; status: string } | null
  journal_state: 'no_revision' | 'not_applicable' | 'unposted' | 'posted'
  payments_state: 'not_materialized' | 'materialized'
  overall_status: 'info' | 'reconciled' | 'diff'
  categories: PayrollPostingReconciliationCategory[]
}

export const payrollPostingApi = {
  reconciliation: (period: string) =>
    api.get<PayrollPostingReconciliation>('/payroll/posting/reconciliation', {
      params: { period },
    }).then(response => response.data),
}

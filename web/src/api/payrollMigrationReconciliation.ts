import { api } from './client'

// PAM-11 — kontrolní sestava „naše přepočtená mzda vs. mzda převzatá z původního
// systému". Read-only report; žádné mutační metody.

/**
 * Zdroj převzatých mezd; musí sedět na ENUM v migraci 1849 ve znění 1851
 * a na `PayrollMigrationReferenceTotalsWriter::SOURCES`.
 *
 * `other` je obecný zdroj tabulkového importu — převzaté mzdy nejsou vázané na
 * PAMICU, pojmenované zdroje jsou jen ty s vlastním feederem v aplikaci.
 */
export type PayrollMigrationSource = 'pamica' | 'pohoda' | 'money_s3' | 'other' | 'stereo_nx' | 'jmhz'

/**
 * Stav porovnání jedné částky. `reference_missing` / `calculated_missing` NENÍ
 * nulový rozdíl — je to přiznání, že jedna strana chybí a rozdíl nejde změřit.
 */
export type PayrollMigrationCellStatus =
  | 'match'
  | 'differs'
  | 'reference_missing'
  | 'calculated_missing'

/** Porovnávané veličiny na řádku osoby. `employer_social` je jen v součtech. */
export type PayrollMigrationRowMetric =
  | 'gross'
  | 'net'
  | 'social_base'
  | 'health_base'
  | 'employee_social'
  | 'employee_health'
  | 'employer_health'
  | 'advance_tax'
  | 'withholding_tax'
  | 'tax_bonus'

export type PayrollMigrationTotalMetric = PayrollMigrationRowMetric | 'employer_social'

export interface PayrollMigrationCell {
  reference_minor: number | null
  calculated_minor: number | null
  /** `null`, když jedna strana chybí — nikdy 0. */
  difference_minor: number | null
  status: PayrollMigrationCellStatus
}

export interface PayrollMigrationTotalCell extends PayrollMigrationCell {
  /** Do součtu nepřispěly všechny řádky (některé nemají protějšek). */
  incomplete: boolean
}

export interface PayrollMigrationRelationship {
  external_relationship_ref: string
  employment_id: number | null
  gross_minor: number
}

export interface PayrollMigrationRow {
  period: string
  employee_id: number | null
  full_name: string | null
  /** Identifikátor osoby v původním systému; jediné, co zbývá u nenapárované osoby. */
  external_person_ref: string | null
  presence: 'both' | 'reference_only' | 'calculated_only'
  relationships: PayrollMigrationRelationship[]
  metrics: Record<PayrollMigrationRowMetric, PayrollMigrationCell>
  max_abs_difference_minor: number | null
  has_deviation: boolean
}

export interface PayrollMigrationMonth {
  period: string
  rows: PayrollMigrationRow[]
  totals: Record<PayrollMigrationTotalMetric, PayrollMigrationTotalCell>
  row_count: number
  deviation_count: number
  missing_counterpart_count: number
  /** Stav revize, ze které se čte naše strana; `mixed` = víc běhů v různém stavu. */
  calculated_revision_status: string | null
}

export interface PayrollMigrationDeviation {
  period: string
  employee_id: number | null
  full_name: string | null
  external_person_ref: string | null
  metric: PayrollMigrationRowMetric
  reference_minor: number | null
  calculated_minor: number | null
  difference_minor: number | null
  status: PayrollMigrationCellStatus
}

export interface PayrollMigrationReconciliation {
  year: number
  row_metrics: PayrollMigrationRowMetric[]
  total_metrics: PayrollMigrationTotalMetric[]
  months: PayrollMigrationMonth[]
  totals: Record<PayrollMigrationTotalMetric, PayrollMigrationTotalCell>
  deviations: PayrollMigrationDeviation[]
  summary: {
    row_count: number
    deviation_count: number
    missing_counterpart_count: number
    max_abs_difference_minor: number | null
  }
  sources: PayrollMigrationSource[]
  source: PayrollMigrationSource | null
}

export const payrollMigrationReconciliationApi = {
  report: (year: number, source?: PayrollMigrationSource | null) =>
    api.get<{ report: PayrollMigrationReconciliation }>(
      `/payroll/reports/migration-reconciliation/${year}${source ? `?source=${encodeURIComponent(source)}` : ''}`,
    ).then(response => response.data.report),
}

// ─── Převzaté mzdy roku přechodu (PAM-09) ─────────────────────────────────────
// Přehled toho, co je za rok k dispozici, a obecný tabulkový import z libovolného
// mzdového systému. Nad týmiž daty stojí evidenční list důchodového pojištění
// a zpětná evidence plateb, takže „chybí měsíc" je tu stejně důležité jako čísla.

/** Odkud je měsíc. `none` = odnikud, tedy díra v roce. */
export type PayrollTakeoverPresence = 'takeover_only' | 'calculated_only' | 'both' | 'none'

export interface PayrollTakeoverPeriod {
  period: string
  presence: PayrollTakeoverPresence
  /** Období předchází `payroll_module_state.start_period`. */
  historical: boolean
  takeover_row_count: number
  takeover_employee_count: number
  sources: PayrollMigrationSource[]
}

export interface PayrollTakeoverOverview {
  supplier_id: number
  year: number
  employee_id: number | null
  employment_id: number | null
  payroll_start_period: string | null
  sources: PayrollMigrationSource[]
  periods: PayrollTakeoverPeriod[]
  takeover_periods: string[]
  calculated_periods: string[]
  /** Měsíce vedené z obou stran — dvojí evidence, nebo kontrola přepočtu. */
  overlapping_periods: string[]
  /** Měsíce roku bez podkladu z kterékoli strany. */
  missing_periods: string[]
  months: Record<string, unknown>[]
}

export interface PayrollTakeoverImportError {
  row_number: number
  error_code: string
  field_name: string | null
  error_message: string
}

export interface PayrollTakeoverImportPreview {
  format: string
  source: PayrollMigrationSource
  source_name: string
  row_count: number
  errors: PayrollTakeoverImportError[]
  periods: { period: string; row_count: number; gross_minor: number }[]
  people: {
    employee_id: number | null
    employee_name: string
    month_count: number
    gross_minor: number
    net_payable_minor: number
  }[]
}

export interface PayrollTakeoverImportResult {
  format: string
  source: PayrollMigrationSource
  source_name: string
  written: number
  employee_count: number
  periods: string[]
}

interface PayrollTakeoverImportRequest {
  source: PayrollMigrationSource
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
}

export const payrollTakeoverWagesApi = {
  overview: (year: number, source?: PayrollMigrationSource | null) =>
    api.get<{ takeover: PayrollTakeoverOverview }>(
      `/payroll/takeover-wages/${year}${source ? `?source=${encodeURIComponent(source)}` : ''}`,
    ).then(response => response.data.takeover),
  person: (year: number, employeeId: number) =>
    api.get<{ takeover: PayrollTakeoverOverview }>(
      `/payroll/takeover-wages/${year}/people/${employeeId}`,
    ).then(response => response.data.takeover),
  importTemplateUrl: '/api/payroll/takeover-wages/import/template',
  importPreview: (payload: PayrollTakeoverImportRequest) =>
    api.post<{ preview: PayrollTakeoverImportPreview }>(
      '/payroll/takeover-wages/import/preview',
      payload,
    ).then(response => response.data.preview),
  importApply: (payload: PayrollTakeoverImportRequest) =>
    api.post<{ import: PayrollTakeoverImportResult }>(
      '/payroll/takeover-wages/import/apply',
      payload,
    ).then(response => response.data.import),
}

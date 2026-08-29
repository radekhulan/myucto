import { api } from './client'

/**
 * Druhy nepřítomnosti. Pořadí zrcadlí `payroll_absences.absence_type`
 * (kontrakt hlídá `PayrollEnumContractTest`).
 *
 * `unexcused` je NEOMLUVENÁ nepřítomnost a stojí zvlášť schválně: jen o ni se
 * podle § 223 odst. 1 zákoníku práce smí krátit dovolená. `employee_obstacle`
 * je proti tomu překážka v práci (§ 191 a násl.), tedy nepřítomnost OMLUVENÁ,
 * za kterou krátit nelze; `other` je zbytková kategorie, ze které by se právní
 * následek odvozovat neměl. Za neomluveně zameškanou dobu mzda ani náhrada
 * nepřísluší — server proto u tohohle druhu vynucuje `compensation_policy`
 * `none`.
 */
export type AbsenceType =
  | 'vacation' | 'dpn' | 'quarantine' | 'ocr' | 'long_term_care' | 'ppm'
  | 'paternity' | 'parental' | 'unpaid_leave' | 'employee_obstacle'
  | 'employer_obstacle' | 'compensatory_time_off' | 'unexcused' | 'other'

export interface PayrollAbsenceEmployment {
  id: number
  employee_id: number
  code: string
  relation_type: string
  status: string
  full_name: string
}

export interface AverageSnapshot {
  id: number
  employment_id: number
  applicable_year: number
  applicable_quarter: number
  source_kind: 'actual' | 'probable'
  average_hourly_minor: number
  rationale: string | null
  support_status: 'manual_review'
  status: 'manual_review' | 'approved' | 'superseded'
  row_version: number
}

export interface PayrollAbsence {
  id: number
  employment_id: number
  full_name: string
  employment_code: string
  absence_type: AbsenceType
  date_from: string
  date_to: string
  partial_first_minutes: number | null
  partial_last_minutes: number | null
  average_snapshot_id: number | null
  average_hourly_minor: number | null
  note: string | null
  support_status: 'manual_review'
  status: 'requested' | 'approved' | 'rejected' | 'cancelled'
  correction_pending: boolean
  row_version: number
}

export interface LeaveEntry {
  id: number
  employment_id: number
  leave_year: number
  effective_date: string
  entry_type: string
  minutes_delta: number
  reason: string
  support_status: 'manual_review'
}

export interface AbsencePayload {
  employment_id: number
  absence_type: AbsenceType
  date_from: string
  date_to: string
  timezone_name: string
  partial_first_minutes: number | null
  partial_last_minutes: number | null
  average_snapshot_id: number | null
  note: string | null
}

export interface PayrollAbsencesPage {
  absences: PayrollAbsence[]
  total: number
  limit: number
  offset: number
}

export interface LeaveEntitlementCandidate {
  employment_id: number
  employee_name: string
  employment_code: string
  relation_type: string
  period_from: string
  period_to: string
  weekly_minutes: number | null
  entitlement_weeks: number | null
  allowance_source: 'company_policy' | 'employment_override' | 'mixed_same_value' | null
  continuous_calendar_days: number
  worked_equivalent_minutes: number
  ready: boolean
  blockers: string[]
  input_version: string
}

export interface LeaveEntitlementCandidatesPage {
  items: LeaveEntitlementCandidate[]
  total: number
  limit: number
  offset: number
}

export const payrollAbsenceApi = {
  context: () =>
    api.get<{ employments: PayrollAbsenceEmployment[] }>('/payroll/time/context')
      .then(response => response.data.employments),
  /**
   * Stránka nepřítomností. Server strop drží tvrdě (výchozí 50, maximum 200),
   * takže bez `limit` a `offset` bychom viděli jen první stránku a o zbytku
   * mlčeli — `total` je jediné, z čeho se pozná, že další záznamy existují.
   */
  absencesPage: (
    from: string,
    to: string,
    employmentId?: number,
    page?: { limit?: number, offset?: number },
  ) =>
    api.get<PayrollAbsencesPage>('/payroll/time/absences', {
      params: {
        from,
        to,
        employment_id: employmentId,
        ...(page?.limit === undefined ? {} : { limit: page.limit }),
        ...(page?.offset === undefined ? {} : { offset: page.offset }),
      },
    }).then(response => response.data),
  // Nestránkovaný přehled pro karty zaměstnanců — vrací jen serverovou výchozí
  // stránku, na plný seznam je `absencesPage()`.
  absences: (from: string, to: string, employmentId?: number) =>
    api.get<PayrollAbsencesPage>('/payroll/time/absences', {
      params: { from, to, employment_id: employmentId },
    }).then(response => response.data.absences),
  createAbsence: (payload: AbsencePayload) =>
    api.post<{ absence: PayrollAbsence }>('/payroll/time/absences', payload)
      .then(response => response.data.absence),
  decide: (id: number, payload: {
    row_version: number
    decision: 'approved' | 'rejected'
    first_day_fully_worked?: boolean
    insurance_eligibility_confirmed?: boolean
    conflicting_benefit_excluded?: boolean
    /**
     * Poskytnout dovolenou nad rámec zůstatku. Posílá se AŽ POTOM, co server
     * schválení odmítl s 409 `leave_overdraw_confirmation_required` — dopředu
     * by to bylo zaškrtávátko, které nikdo nečte.
     */
    overdraw_confirmed?: boolean
  }) =>
    api.post<{ absence: PayrollAbsence }>(`/payroll/time/absences/${id}/decision`, payload)
      .then(response => response.data.absence),
  cancel: (id: number, rowVersion: number) =>
    api.post<{ absence: PayrollAbsence }>(`/payroll/time/absences/${id}/cancel`, {
      row_version: rowVersion,
    }).then(response => response.data.absence),
  averages: (employmentId: number) =>
    api.get<{ snapshots: AverageSnapshot[] }>('/payroll/time/averages', {
      params: { employment_id: employmentId },
    }).then(response => response.data.snapshots),
  createAverage: (payload: Record<string, unknown>) =>
    api.post<{ snapshot: AverageSnapshot }>('/payroll/time/averages', payload)
      .then(response => response.data.snapshot),
  approveAverage: (id: number, rowVersion: number) =>
    api.post<{ snapshot: AverageSnapshot }>(`/payroll/time/averages/${id}/approve`, {
      row_version: rowVersion,
    }).then(response => response.data.snapshot),
  leaveLedger: (employmentId: number, year: number) =>
    api.get<{ entries: LeaveEntry[]; balance_minutes: number }>('/payroll/time/leave-ledger', {
      params: { employment_id: employmentId, year },
    }).then(response => response.data),
  createLeaveEntry: (payload: Record<string, unknown>) =>
    api.post<{ entry: LeaveEntry }>('/payroll/time/leave-ledger', payload)
      .then(response => response.data.entry),
  createEntitlement: (payload: Record<string, unknown>) =>
    api.post('/payroll/time/leave-entitlements', payload).then(response => response.data.entitlement),
  leaveEntitlementCandidates: (
    year: number,
    through: string,
    page: { limit: number, offset: number },
  ) => api.get<LeaveEntitlementCandidatesPage>('/payroll/time/leave-entitlement-candidates', {
    params: { year, through, ...page },
  }).then(response => response.data),
  createAutomaticEntitlements: (payload: {
    year: number
    through: string
    items: Array<{ employment_id: number, input_version: string }>
  }) => api.post<{ entitlements: unknown[] }>('/payroll/time/leave-entitlements/bulk', payload)
    .then(response => response.data.entitlements),
}

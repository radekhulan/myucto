import { api } from './client'
import type { PayrollAbsenceSicknessCaseOutcome } from './payrollSicknessCases'

/**
 * Výsledek schválení nebo zrušení nepřítomnosti. `sickness_case` říká, co se
 * stalo s případem dávky nemocenského pojištění (§ 97 zák. č. 187/2006 Sb.):
 * `null` = z nepřítomnosti žádná dávka neplyne.
 */
export interface PayrollAbsenceDecisionResult {
  absence: PayrollAbsence
  /**
   * Výpočet ze schválení. U placené překážky nese `warning`
   * `obstacle_without_published_shifts`, když náhrada nevznikla, protože
   * na dny překážky nejsou rozvržené směny.
   */
  calculation?: { warning?: string | null } | null
  sickness_case?: PayrollAbsenceSicknessCaseOutcome | null
  /** Co je po schválení potřeba opravit jinde (docházka, schválený běh). */
  warnings?: Array<{ code: string, message: string, path: string }>
}

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
 *
 * `public_function` (výkon veřejné funkce, § 200 až 202 ZP) a
 * `employee_obstacle_unpaid` jsou pracovní volno BEZ náhrady mzdy; placená
 * překážka zůstává `employee_obstacle`.
 *
 * `invalid_termination` je doba, po kterou podle pravomocného rozhodnutí soudu
 * vztah trval po neplatném skončení bez přiznané náhrady mzdy (§ 16 odst. 4
 * písm. j) zákona č. 155/1995 Sb., v hlášení vyloučená doba 10536).
 */
export type AbsenceType =
  | 'vacation' | 'dpn' | 'quarantine' | 'ocr' | 'long_term_care' | 'ppm'
  | 'paternity' | 'parental' | 'unpaid_leave' | 'employee_obstacle'
  | 'employer_obstacle' | 'compensatory_time_off' | 'unexcused' | 'other'
  | 'public_function' | 'employee_obstacle_unpaid' | 'invalid_termination'

/**
 * Druh placené překážky v práci. Pořadí zrcadlí `payroll_absences.obstacle_kind`
 * (kontrakt hlídá `PayrollEnumContractTest`). Sazbu náhrady a její meze nese
 * {@link PayrollObstacleKindRule} ze serveru, tady se nic nepočítá.
 */
export type ObstacleKind =
  | 'medical_examination' | 'commute_prevented_disabled' | 'own_wedding'
  | 'child_wedding' | 'childbirth_transport' | 'death_close_relative'
  | 'death_relative' | 'family_escort' | 'disabled_child_escort'
  | 'coworker_funeral' | 'relocation_employer_interest' | 'job_search_redundancy'
  | 'blood_donation' | 'employee_representation' | 'qualification_training'
  | 'other_paid_employee' | 'downtime' | 'weather_interruption'
  | 'other_employer_obstacle' | 'partial_unemployment' | 'partial_work'

export interface PayrollObstacleKindRule {
  kind: ObstacleKind
  absence_type: 'employee_obstacle' | 'employer_obstacle'
  default_rate_basis_points: number
  min_rate_basis_points: number
  max_rate_basis_points: number
  requires_reason: boolean
  statutory_basis: string
}

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
  /** Druh pracovního vztahu; u DPP a DPČ se nárok na náhradu při DPN ověřuje zvlášť. */
  relation_type?: string
  absence_type: AbsenceType
  date_from: string
  date_to: string
  /** Jen u `ppm`: od něj se odvozuje předporodní část (vyloučená doba ELDP). */
  expected_childbirth_date?: string | null
  /** Jen u `ppm`; doplňuje se jednou, i po schválení (`recordChildbirth`). */
  childbirth_date?: string | null
  /** Jen u `ocr`: podpůrčí doba ošetřovného 16 dnů místo 9. */
  lone_carer?: boolean
  partial_first_minutes: number | null
  partial_last_minutes: number | null
  average_snapshot_id: number | null
  average_hourly_minor: number | null
  note: string | null
  support_status: 'manual_review'
  status: 'requested' | 'approved' | 'rejected' | 'cancelled'
  correction_pending: boolean
  /**
   * Jen u `dpn`/`quarantine`: dny 14denního okna náhrady mzdy (§ 192 ZP)
   * vyčerpané PŘED `date_from` u předchozího zaměstnavatele nebo předchozího
   * mzdového programu. 0 = případ začal v MyÚčtu. Zapisuje se samostatnou
   * akcí ({@link payrollAbsenceApi.setSicknessWindowCarried}).
   */
  sickness_window_carried_days: number
  /** Jen u placené překážky: druh, podle kterého se určila sazba náhrady. */
  obstacle_kind?: ObstacleKind | null
  /** Sazba náhrady v bazických bodech (10 000 = 100 % průměru). */
  compensation_rate_basis_points?: number | null
  compensation_rate_reason?: string | null
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
  expected_childbirth_date: string | null
  childbirth_date: string | null
  /** Jen u `ocr`: osamělý zaměstnanec s dítětem do 16 let, ošetřovné 16 dnů. */
  lone_carer?: boolean
  timezone_name: string
  partial_first_minutes: number | null
  partial_last_minutes: number | null
  average_snapshot_id: number | null
  note: string | null
  /** Jen u `employee_obstacle` / `employer_obstacle`, jinak se neposílá. */
  obstacle_kind?: ObstacleKind | null
  compensation_rate_basis_points?: number | null
  compensation_rate_reason?: string | null
}

export interface PayrollAbsencesPage {
  absences: PayrollAbsence[]
  total: number
  limit: number
  offset: number
}

/**
 * Návrh vstupů průměrného výdělku odvozený z uzavřených mzdových běhů.
 *
 * `ready === false` znamená, že se odvodit NEDÁ — čísla jsou pak `null` a
 * důvod nese `blockers`. Částečný návrh se nevrací nikdy: sečtený neúplný
 * základ vypadá jako hotové číslo a nikdo na něm nepozná, že měsíc chybí.
 *
 * `longer_period_allocated_minor` je vždy `null`: poměrnou část mzdy za období
 * delší než čtvrtletí (§ 358 ZP) aplikace v datech nerozlišuje.
 */
export interface AverageEarningSuggestion {
  employment_id: number
  applicable_year: number
  applicable_quarter: number
  decisive_from: string
  decisive_to: string
  minimum_worked_days: number
  ready: boolean
  blockers: string[]
  /**
   * Ze kterého zdroje průměr vznikne: `actual` = skutečný průměr z uzavřených
   * běhů, `probable` = pravděpodobný výdělek zadaný v podmínkách vztahu
   * (§ 355 ZP). `null`, dokud je návrh blokovaný.
   */
  source_kind: 'actual' | 'probable' | null
  /** Proč skutečný průměr nevyšel; u `source_kind: 'actual'` prázdné. */
  actual_blockers: string[]
  probable_hourly_minor: number | null
  probable_rationale: string | null
  probable_term_id: number | null
  gross_earnings_minor: number | null
  longer_period_allocated_minor: null
  worked_minutes: number | null
  worked_days: number | null
  /**
   * Měsíce (`YYYY-MM`) vzaté z převzatých mezd předchozího programu. Jejich
   * hrubá mzda může obsahovat náhrady mzdy, které do průměru nepatří.
   */
  takeover_periods?: string[]
  months: Array<{
    period_start: string
    run_id: number | null
    revision_id: number | null
    revision_no: number | null
    gross_earnings_minor: number | null
    worked_minutes: number | null
    worked_days: number | null
    work_summary_id: number | null
    takeover?: boolean
    blockers: string[]
  }>
  input_version: string
}

/**
 * Kandidát na hromadné založení průměrného výdělku za čtvrtletí. Na rozdíl od
 * `AverageEarningSuggestion` (jeden vztah, formulář) je tu vždy `ready` nebo
 * `blockers` — hromadná akce nemá kam ukázat rozpad po měsících.
 */
export interface AverageEarningCandidate {
  employment_id: number
  employee_name: string
  employment_code: string
  decisive_from: string
  decisive_to: string
  ready: boolean
  blockers: string[]
  source_kind: 'actual' | 'probable' | null
  probable_source: 'terms' | 'achieved_wage' | 'agreed_monthly_gross' | 'minimum_wage' | null
  probable_hourly_minor: number | null
  probable_rationale: string | null
  gross_earnings_minor: number | null
  worked_minutes: number | null
  worked_days: number | null
  input_version: string
  existing: {
    id: number
    status: 'manual_review' | 'approved'
    source_kind: 'actual' | 'probable'
    average_hourly_minor: number
  } | null
  /** Běh rozhodného období se po schválení průměru opravil a průměr na něj nesedí. */
  existing_outdated?: boolean
}

export interface AverageEarningCandidatesPage {
  items: AverageEarningCandidate[]
  total: number
  limit: number
  offset: number
}

export interface LeaveEntitlementCandidate {
  employment_id: number
  employee_id?: number
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
  /** Nárok roku určil předchozí program; zůstatek přišel převodem a znovu se nepočítá. */
  takeover?: { minutes: number, effective_date: string, reason: string } | null
  /** Jiné schválené absence, o jejichž započtení musí rozhodnout účetní. */
  assessment_absences?: LeaveAssessmentAbsence[]
  /** Absence z doby před MyÚčtem, které posoudil předchozí program. */
  predecessor_absences?: number
}

export type LeaveAbsenceDecision = 'include' | 'exclude'

export interface LeaveAssessmentAbsence {
  id: number
  row_version: number
  absence_type: string
  date_from: string
  date_to: string
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
  /** Vztahy i tabulka druhů překážek s jejich sazbami (jeden požadavek). */
  absenceContext: () =>
    api.get<{
      employments: PayrollAbsenceEmployment[]
      obstacle_kinds?: PayrollObstacleKindRule[]
    }>('/payroll/time/context')
      .then(response => ({
        employments: response.data.employments,
        obstacleKinds: response.data.obstacle_kinds ?? [],
      })),
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
    /** Výslovná volba DPN bez nároku (§ 15a zák. č. 187/2006 Sb.): nulová náhrada. */
    insurance_eligibility?: 'confirmed' | 'not_eligible'
    /** Snížení náhrady podle § 192 odst. 4 (polovina) nebo odst. 5 ZP (porušení režimu). */
    compensation_reduction?: 'none' | 'half_192_4' | 'reduced_192_5'
    /** O kolik se snižuje u § 192 odst. 5, v bazických bodech (10 000 = neposkytnout). */
    compensation_reduction_basis_points?: number
    /** O kolik haléřů se snižuje u § 192 odst. 5 (jen náhrada v jednom měsíci). */
    compensation_reduction_minor?: number
    compensation_reduction_reason?: string
    /**
     * Poskytnout dovolenou nad rámec zůstatku. Posílá se AŽ POTOM, co server
     * schválení odmítl s 409 `leave_overdraw_confirmation_required` — dopředu
     * by to bylo zaškrtávátko, které nikdo nečte.
     */
    overdraw_confirmed?: boolean
  }) =>
    api.post<PayrollAbsenceDecisionResult>(`/payroll/time/absences/${id}/decision`, payload)
      .then(response => response.data),
  cancel: (id: number, rowVersion: number) =>
    api.post<PayrollAbsenceDecisionResult>(`/payroll/time/absences/${id}/cancel`, {
      row_version: rowVersion,
    }).then(response => response.data),
  recordChildbirth: (id: number, rowVersion: number, childbirthDate: string) =>
    api.post<{ absence: PayrollAbsence }>(`/payroll/time/absences/${id}/childbirth`, {
      row_version: rowVersion,
      childbirth_date: childbirthDate,
    }).then(response => response.data.absence),
  /**
   * Zapíše dny okna náhrady mzdy (§ 192 ZP) vyčerpané u DPN/karantény ještě
   * PŘED touhle nepřítomností — u předchozího zaměstnavatele nebo předchozího
   * mzdového programu. Server odmítne jiný druh absence, uzavřený rok i
   * absenci se spočítanou náhradou (viz `PayrollAbsenceRepository::setSicknessWindowCarriedDays`).
   */
  setSicknessWindowCarried: (id: number, rowVersion: number, days: number) =>
    api.post<{ absence: PayrollAbsence }>(`/payroll/time/absences/${id}/sickness-window-carried`, {
      row_version: rowVersion,
      sickness_window_carried_days: days,
    }).then(response => response.data.absence),
  averages: (employmentId: number) =>
    api.get<{ snapshots: AverageSnapshot[] }>('/payroll/time/averages', {
      params: { employment_id: employmentId },
    }).then(response => response.data.snapshots),
  /**
   * Návrh vstupů průměru. Čte jen zmrazené běhy, nic neukládá — průměr vzniká
   * až tím, že účetní čísla potvrdí a odešle `createAverage`.
   */
  averageSuggestion: (employmentId: number, year: number, quarter: number) =>
    api.get<{ suggestion: AverageEarningSuggestion }>('/payroll/time/averages/suggestion', {
      params: {
        employment_id: employmentId,
        applicable_year: year,
        applicable_quarter: quarter,
      },
    }).then(response => response.data.suggestion),
  createAverage: (payload: Record<string, unknown>) =>
    api.post<{ snapshot: AverageSnapshot }>('/payroll/time/averages', payload)
      .then(response => response.data.snapshot),
  approveAverage: (id: number, rowVersion: number) =>
    api.post<{ snapshot: AverageSnapshot }>(`/payroll/time/averages/${id}/approve`, {
      row_version: rowVersion,
    }).then(response => response.data.snapshot),
  /**
   * `payroll_start_period` (RRRR-MM) je hranice, před kterou smí vzniknout ručně
   * zapsané čerpání převzaté z předchozího mzdového programu. Null = firma
   * období zahájení nastavené nemá, takže se ručně nezapisuje nic.
   */
  leaveLedger: (employmentId: number, year: number) =>
    api.get<{
      entries: LeaveEntry[]
      balance_minutes: number
      payroll_start_period: string | null
    }>('/payroll/time/leave-ledger', {
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
    /** Hromadné posouzení jiných absencí: druh => započítat / nezapočítat. */
    absence_decisions?: Record<string, LeaveAbsenceDecision>
  }) => api.post<{ entitlements: unknown[] }>('/payroll/time/leave-entitlements/bulk', payload)
    .then(response => response.data.entitlements),
  averageCandidates: (
    year: number,
    quarter: number,
    page: { limit: number, offset: number },
  ) => api.get<AverageEarningCandidatesPage>('/payroll/time/average-candidates', {
    params: { year, quarter, ...page },
  }).then(response => response.data),
  createAveragesBulk: (payload: {
    year: number
    quarter: number
    items: Array<{ employment_id: number, input_version: string }>
  }) => api.post<{ averages: AverageSnapshot[] }>('/payroll/time/averages/bulk', payload)
    .then(response => response.data.averages),
}

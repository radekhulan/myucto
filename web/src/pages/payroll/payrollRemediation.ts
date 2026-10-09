export interface EldpBlocker {
  code: string
  message: string
  detail?: { period_start?: string, employment_id?: number, [key: string]: unknown }
}

export const eldpRemediationCodes: Record<string, string> = {
  eldp_no_source_revision: 'no_revisions',
  eldp_month_source_missing: 'missing_month',
  eldp_month_outside_employment: 'dates',
  eldp_month_source_ambiguous: 'ambiguous',
  eldp_revision_not_current_approved: 'revision',
  eldp_source_mismatch: 'integrity',
  eldp_employment_not_in_revision: 'missing_employment',
  eldp_employment_dates_missing: 'dates',
  eldp_employment_dates_inconsistent: 'dates',
  eldp_relationship_kind_unsupported: 'unsupported',
  eldp_activity_unsupported: 'activity',
  eldp_assessment_base_missing: 'social',
  eldp_assessment_base_not_whole_czk: 'integrity',
  eldp_absences_invalid: 'integrity',
  eldp_excluded_days_exceed_period: 'absence_overlap',
  eldp_social_not_calculated: 'social',
  eldp_social_relationship_ambiguous: 'integrity',
  eldp_social_participation_missing: 'social',
  eldp_absence_source_invalid: 'integrity',
  eldp_absence_interval_invalid: 'absence_dates',
  eldp_absence_kind_unsupported: 'unsupported',
  eldp_absence_kind_unknown: 'integrity',
  eldp_absence_overlap_unsupported: 'absence_overlap',
  eldp_ppm_expected_childbirth_missing: 'absence_dates',
  eldp_ppm_childbirth_missing: 'absence_dates',
  eldp_insurance_month_without_income: 'income_month',
  eldp_source_hash_mismatch: 'integrity',
  eldp_source_invalid: 'integrity',
  eldp_xml_snapshot_mismatch: 'integrity',
  // Rok přechodu z jiného mzdového programu: náprava je v převzatých mzdách,
  // ne ve mzdách MyÚčta. Výjimkou je měsíc, který MyÚčto počítá a jen mu chybí
  // schválení — ten se řeší v běhu, jinak by převzatá data zakryla jiná čísla.
  eldp_takeover_month_not_substitutable: 'takeover_revision',
  eldp_takeover_month_ambiguous: 'takeover',
  eldp_takeover_relationship_kind_unsupported: 'takeover',
  eldp_takeover_activity_missing: 'takeover',
  eldp_takeover_employment_dates_inconsistent: 'takeover',
  eldp_takeover_employment_dates_ambiguous: 'takeover',
  eldp_takeover_employment_end_unknown: 'takeover',
  eldp_takeover_employment_unresolved: 'takeover',
  eldp_takeover_post_termination_income_unsupported: 'takeover',
  eldp_takeover_participation_conflict: 'takeover',
  eldp_takeover_insurance_days_missing: 'takeover',
  eldp_takeover_insurance_days_exceed_period: 'takeover',
  eldp_takeover_excluded_days_breakdown_missing: 'takeover',
  eldp_takeover_excluded_days_mismatch: 'takeover',
  eldp_deducted_days_unknown: 'unsupported',
  eldp_section_15a_history_unavailable: 'unsupported',
  eldp_statement_continues_previous_employment: 'previous_employment',
  eldp_post_termination_small_scale_unsupported: 'unsupported',
  eldp_excluded_days_exceed_deducted: 'integrity',
  eldp_base_with_fully_excluded_section: 'absence_overlap',
  eldp_takeover_assessment_base_missing: 'takeover',
  eldp_takeover_assessment_base_not_whole_czk: 'takeover',
  eldp_post_termination_code_missing: 'unsupported',
  // Náprava je na téže obrazovce: datum vyhotovení a volba opravného listu.
  eldp_prepared_on_before_period_end: 'prepared_on',
  eldp_prepared_on_invalid: 'prepared_on',
  eldp_prepared_on_future: 'prepared_on',
  eldp_scope_already_frozen: 'correction',
  eldp_correction_without_change: 'correction',
  eldp_correction_without_original: 'correction',
  // Opravný list bez platného odkazu na zmrazený opravovaný list: interní
  // nesoulad podkladu, účetní ho ve formuláři opravit nemůže.
  eldp_correction_reference_invalid: 'integrity',
  // Potvrzení, dokument a rok bez pojištění se řeší přímo ve formuláři
  // evidenčního listu; proklik jinam by účetní odvedl od místa, kde je pole.
  eldp_excluded_days_not_confirmed: 'confirmation',
  eldp_authority_request_date_invalid: 'confirmation',
  eldp_confirmation_invalid: 'confirmation',
  eldp_confirmation_note_invalid: 'confirmation',
  eldp_death_date_invalid: 'confirmation',
  eldp_year_out_of_range: 'confirmation',
  eldp_manual_date_future: 'confirmation',
  eldp_manual_date_invalid: 'confirmation',
  eldp_manual_reference_invalid: 'confirmation',
  eldp_manual_status_invalid: 'confirmation',
  eldp_confirmation_document_required: 'confirmation_document',
  eldp_confirmation_document_missing: 'confirmation_document',
  eldp_confirmation_document_corrupt: 'confirmation_document',
  eldp_authority_request_due_on_missing: 'confirmation',
  eldp_authority_request_due_on_invalid: 'confirmation',
  // Důchodové údaje zaměstnance se doplňují ve formuláři evidenčního listu.
  eldp_pension_status_not_confirmed: 'pension',
  eldp_pension_status_invalid: 'pension',
  eldp_pension_status_conflict: 'pension',
  eldp_pension_status_evidence_mismatch: 'pension',
  eldp_not_kept_full_old_age_pension: 'pension_not_kept',
  eldp_pension_age_mid_month_unsupported: 'unsupported',
  eldp_no_insurance_period: 'no_insurance',
  eldp_standalone_statement_not_applicable: 'not_applicable',
  eldp_manual_already_fulfilled: 'manual_state',
  eldp_manual_completion_frozen: 'manual_state',
  eldp_manual_submitted_frozen: 'manual_state',
  eldp_excluded_days_sum_mismatch: 'absence_overlap',
  eldp_employment_scope_mismatch: 'integrity',
  eldp_environment_invalid: 'integrity',
  eldp_hash_mismatch: 'integrity',
  eldp_idempotency_payload_mismatch: 'integrity',
  eldp_idempotency_scope_mismatch: 'integrity',
  eldp_submission_replay_mismatch: 'integrity',
  eldp_submission_replay_state_invalid: 'integrity',
  eldp_control_submission_state_invalid: 'integrity',
  eldp_manual_statement_not_found: 'integrity',
  eldp_schema_integrity_failed: 'integrity',
  eldp_submission_schema_unavailable: 'integrity',
  eldp_xml_source_invalid: 'integrity',
  eldp_xsd_validation_failed: 'integrity',
}

/** Kinds, jejichž náprava je ve formuláři evidenčního listu samotném. */
const ELDP_LOCAL_KINDS = ['confirmation', 'confirmation_document', 'no_insurance', 'not_applicable', 'manual_state', 'pension', 'pension_not_kept']

export function eldpRemediation(blocker: EldpBlocker, selectedEmploymentId: number | null, year: number) {
  const kind = Object.hasOwn(eldpRemediationCodes, blocker.code) ? eldpRemediationCodes[blocker.code]! : 'unknown'
  const employmentId = blocker.detail?.employment_id ?? selectedEmploymentId
  const hasEmployment = Number.isInteger(employmentId) && Number(employmentId) > 0
  const period = /^\d{4}-(0[1-9]|1[0-2])-01$/.test(blocker.detail?.period_start ?? '')
    ? blocker.detail!.period_start!.slice(0, 7) : null
  let path: string | null = '/admin/support'
  let action = 'support'
  if (ELDP_LOCAL_KINDS.includes(kind)) {
    path = null
    action = 'form'
  } else if (['missing_month', 'revision', 'missing_employment', 'social', 'no_revisions', 'takeover_revision'].includes(kind)) {
    path = '/payroll/runs' + (period ? `?period=${period}` : '')
    action = 'runs'
  } else if (kind === 'takeover') {
    path = '/payroll/imports?tab=reconciliation'
    action = 'takeover'
  } else if (['activity', 'dates'].includes(kind) && hasEmployment) {
    path = `/payroll/people?employment=${employmentId}`
      + (kind === 'activity' ? '&panel=employment_terms&field=activity_code' : '')
    action = 'terms'
  } else if (['prepared_on', 'correction'].includes(kind)) {
    path = '/payroll/submissions/eldp'
    action = 'eldp_form'
  } else if (kind === 'previous_employment') {
    // Navazující zaměstnání patří do listu dřívějšího vztahu: proklik otevře
    // formulář evidenčního listu rovnou u něj.
    const previous = Number(blocker.detail?.previous_employment_id)
    path = Number.isInteger(previous) && previous > 0
      ? `/payroll/submissions/eldp?employment=${previous}&year=${year}`
      : '/payroll/submissions/eldp'
    action = 'eldp_form'
  } else if (['absence_dates', 'absence_overlap', 'income_month'].includes(kind) && hasEmployment) {
    path = `/payroll/absences?employment=${employmentId}&tab=absences`
      + (period ? `&period=${period}` : '')
    action = 'absences'
  }
  return { problemKey: `payroll.remediation.eldp.problems.${kind}`, stepKey: `payroll.remediation.eldp.steps.${kind}`, path, actionKey: `payroll.remediation.actions.${action}`, period, year }
}

export const workSummaryRemediationCodes: Record<string, string> = {
  employment_terms_not_unique_for_month: 'terms',
  absence_not_final: 'absence',
  calendar_day_not_uniquely_covered: 'calendar',
  worked_interval_crosses_month: 'month_boundary',
  worked_intervals_overlap: 'overlap',
  worked_interval_negative: 'break',
  worked_interval_invalid: 'integrity',
  worked_source_missing: 'integrity',
  work_source_conflict: 'work_source',
  import_summary_missing: 'import_missing',
  import_worked_hours_missing: 'import_values',
  import_overtime_invalid: 'import_values',
  import_overtime_exceeds_worked: 'import_values',
}

export function workSummaryRemediation(code: string, employmentId: number, period: string) {
  const kind = Object.hasOwn(workSummaryRemediationCodes, code) ? workSummaryRemediationCodes[code]! : 'unknown'
  const support = kind === 'unknown' || kind === 'integrity'
  const imports = kind === 'import_missing' || kind === 'import_values'
  const path = kind === 'terms'
    ? `/payroll/people?employment=${employmentId}&panel=employment_terms&field=weekly_hours`
    : kind === 'absence' ? `/payroll/absences?employment=${employmentId}&tab=absences&period=${encodeURIComponent(period.slice(0, 7))}`
      : support ? '/admin/support' : imports ? '/payroll/imports' : null
  const action = kind === 'terms' ? 'terms' : kind === 'absence' ? 'absences' : support ? 'support' : imports ? 'imports' : kind === 'calendar' ? 'calendar' : 'entries'
  return { problemKey: `payroll.remediation.work.problems.${kind}`, stepKey: `payroll.remediation.work.steps.${kind}`, path, actionKey: `payroll.remediation.actions.${action}`, localTarget: kind === 'calendar' ? 'calendar' : 'entries' }
}

export function averageEarningsTarget(employmentId: number, year?: number | null, quarter?: number | null): string {
  const base = `/payroll/absences?employment=${employmentId}&tab=averages`
  return Number.isInteger(year) && Number(year) >= 1900 && Number(year) <= 9999
    && Number.isInteger(quarter) && Number(quarter) >= 1 && Number(quarter) <= 4
    ? `${base}&year=${year}&quarter=${quarter}` : base
}

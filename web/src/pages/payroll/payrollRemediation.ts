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
  eldp_capped_base_unsupported: 'unsupported',
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
  eldp_source_hash_mismatch: 'integrity',
  eldp_source_invalid: 'integrity',
  eldp_xml_snapshot_mismatch: 'integrity',
}

export function eldpRemediation(blocker: EldpBlocker, selectedEmploymentId: number | null, year: number) {
  const kind = Object.hasOwn(eldpRemediationCodes, blocker.code) ? eldpRemediationCodes[blocker.code]! : 'unknown'
  const employmentId = blocker.detail?.employment_id ?? selectedEmploymentId
  const hasEmployment = Number.isInteger(employmentId) && Number(employmentId) > 0
  const period = /^\d{4}-(0[1-9]|1[0-2])-01$/.test(blocker.detail?.period_start ?? '')
    ? blocker.detail!.period_start!.slice(0, 7) : null
  let path = '/admin/support'
  let action = 'support'
  if (['missing_month', 'revision', 'missing_employment', 'social', 'no_revisions'].includes(kind)) {
    path = '/payroll/runs' + (period ? `?period=${period}` : '')
    action = 'runs'
  } else if (['activity', 'dates'].includes(kind) && hasEmployment) {
    path = `/payroll/people?employment=${employmentId}`
      + (kind === 'activity' ? '&panel=employment_terms&field=activity_code' : '')
    action = 'terms'
  } else if (['absence_dates', 'absence_overlap'].includes(kind) && hasEmployment) {
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
}

export function workSummaryRemediation(code: string, employmentId: number, period: string) {
  const kind = Object.hasOwn(workSummaryRemediationCodes, code) ? workSummaryRemediationCodes[code]! : 'unknown'
  const path = kind === 'terms'
    ? `/payroll/people?employment=${employmentId}&panel=employment_terms&field=weekly_hours`
    : kind === 'absence' ? `/payroll/absences?employment=${employmentId}&tab=absences&period=${encodeURIComponent(period.slice(0, 7))}`
      : kind === 'unknown' ? '/admin/support' : null
  const action = kind === 'terms' ? 'terms' : kind === 'absence' ? 'absences' : kind === 'unknown' ? 'support' : kind === 'calendar' ? 'calendar' : 'entries'
  return { problemKey: `payroll.remediation.work.problems.${kind}`, stepKey: `payroll.remediation.work.steps.${kind}`, path, actionKey: `payroll.remediation.actions.${action}`, localTarget: kind === 'calendar' ? 'calendar' : 'entries' }
}

export function averageEarningsTarget(employmentId: number, year?: number | null, quarter?: number | null): string {
  const base = `/payroll/absences?employment=${employmentId}&tab=averages`
  return Number.isInteger(year) && Number(year) >= 1900 && Number(year) <= 9999
    && Number.isInteger(quarter) && Number(quarter) >= 1 && Number(quarter) <= 4
    ? `${base}&year=${year}&quarter=${quarter}` : base
}

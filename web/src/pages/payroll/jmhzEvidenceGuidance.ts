import type { PayrollJmhzOrdinaryEvidenceScope } from '@/api/payroll'

type Destination = 'run' | 'revision' | 'employment' | 'deductions' | 'agreements' | 'employer' | 'support'
type Definition = { message: string, destination: Destination }

export const jmhzEvidenceCodes: Record<string, Definition> = Object.fromEntries(Object.entries({
  specification_mismatch: ['specification', 'revision'],
  interaction_mismatch: ['interactions', 'revision'],
  selector_mismatch: ['selector', 'revision'],
  scope_mismatch: ['scope', 'revision'],
  source_mismatch: ['source', 'support'],
  values_mismatch: ['values', 'support'],
  confirmation_invalid: ['confirmation', 'revision'],
  catalog_mismatch: ['catalog', 'support'],
  profile_missing: ['profile_missing', 'run'],
  profile_incomplete: ['profile_incomplete', 'run'],
  monthly_exception_required: ['exception', 'employment'],
  scenario_unsupported: ['unsupported', 'support'],
  deduction_conflict: ['deductions', 'deductions'],
  revision_not_current_approved: ['revision', 'run'],
  period_invalid: ['period', 'support'],
  result_mismatch: ['result', 'run'],
  source_invalid: ['invalid_source', 'support'],
  source_hash_mismatch: ['integrity', 'support'],
  hash_mismatch: ['integrity', 'support'],
  incomplete: ['request', 'support'],
  invalid: ['request', 'support'],
  positive_unsupported: ['positive', 'support'],
  not_found: ['not_found', 'run'],
  scope_already_frozen: ['frozen', 'revision'],
  idempotency_scope_mismatch: ['repeat', 'support'],
  idempotency_payload_mismatch: ['repeat', 'support'],
  idempotency_incomplete: ['incomplete_operation', 'support'],
  // Podpora zaměstnávání OZP (IN28): výdělek OZP se potvrzuje přímo v panelu.
  ozp_wage_required: ['ozp_wage', 'employment'],
  ozp_wage_invalid: ['ozp_wage', 'employment'],
  ozp_wage_unexpected: ['ozp_profile', 'employment'],
  ozp_payment_date_invalid: ['ozp_payment_date', 'employment'],
  ozp_profile_mismatch: ['ozp_profile', 'employment'],
  ozp_deduction_unclassified: ['ozp_deductions', 'agreements'],
  ozp_employer_not_protected: ['ozp_employer', 'employer'],
  // Počet směn rizikové práce (10580, od 01/2027) se zadává přímo v panelu.
  risk_shifts_required: ['risk_shifts', 'employment'],
  risk_shifts_invalid: ['risk_shifts', 'employment'],
  risk_shifts_unexpected: ['risk_shifts', 'employment'],
}).map(([code, [message, destination]]) => [
  `jmhz_ordinary_evidence_${code}`, { message, destination },
])) as Record<string, Definition>
jmhzEvidenceCodes.jmhz_source_invalid = { message: 'invalid_source', destination: 'support' }

const selectorReasons: Record<string, string> = {
  jmhz_scenario_activity_code_missing: 'activity_missing',
  jmhz_scenario_activity_code_invalid: 'activity_invalid',
  jmhz_scenario_relationship_detail_missing: 'detail_missing',
  jmhz_scenario_relationship_detail_invalid: 'detail_invalid',
  jmhz_scenario_relationship_detail_not_applicable: 'detail_not_applicable',
}
/** Nálezy výdělku OZP, které vedou na příznak vztahu v profilu JMHZ. */
const ozpWageCodes = [
  'jmhz_ordinary_evidence_ozp_wage_required',
  'jmhz_ordinary_evidence_ozp_wage_invalid',
  'jmhz_ordinary_evidence_ozp_wage_unexpected',
  'jmhz_ordinary_evidence_ozp_payment_date_invalid',
  'jmhz_ordinary_evidence_ozp_profile_mismatch',
]
const exceptionFields = [
  'orchard_discount_eligible', 'specific_legal_fact_applies',
  'ozp_employment_support_applies', 'deep_mining_work_applies',
]

export function jmhzEvidenceGuidance(scope: PayrollJmhzOrdinaryEvidenceScope, period: string) {
  const code = scope.attention_code ?? ''
  let definition = (Object.hasOwn(jmhzEvidenceCodes, code) ? jmhzEvidenceCodes[code] : undefined) ?? { message: 'unknown', destination: 'support' as const }
  const context = scope.attention_context ?? {}
  if (code === 'jmhz_ordinary_evidence_scenario_unsupported' && selectorReasons[context.reason ?? '']) {
    definition = { message: selectorReasons[context.reason ?? '']!, destination: 'employment' }
  }
  if (code === 'jmhz_ordinary_evidence_deduction_conflict') {
    const reasons: Record<string, string> = {
      missing_title: 'deduction_title', register_unverified: 'deduction_register', calculation_incomplete: 'deduction_calculation',
    }
    definition = { message: reasons[context.reason ?? ''] ?? 'deductions', destination: 'deductions' }
  }
  const paths: Record<Destination, string> = {
    run: `/payroll/runs?period=${encodeURIComponent(period.slice(0, 7))}`,
    revision: `/payroll/runs?period=${encodeURIComponent(period.slice(0, 7))}`,
    employment: `/payroll/people?employment=${scope.employment_id}`,
    deductions: `/payroll/enforcement?person=${scope.employee_id}`,
    agreements: `/payroll/deduction-agreements?person=${scope.employee_id}`,
    employer: '/payroll/settings?tab=employer#payroll-employer-offices',
    support: '/admin/support',
  }
  const ozp = ozpWageCodes.includes(code)
  if (definition.destination === 'employment') {
    const profile = code === 'jmhz_ordinary_evidence_monthly_exception_required' || ozp
    const field = ozp
      ? 'jmhz_ozp_employment_support_applies'
      : code.startsWith('jmhz_ordinary_evidence_risk_shifts_')
      ? 'social_employer_rate_category'
      : profile
      ? exceptionFields.includes(context.field ?? '') ? `jmhz_${context.field}` : undefined
      : context.reason?.includes('activity_code') ? 'activity_code' : 'jmhz_relationship_detail_code'
    paths.employment += `&panel=${profile ? 'jmhz_profile' : 'employment_terms'}`
      + (field ? `&field=${encodeURIComponent(field)}` : '')
  }
  const prefix = 'payroll.submissions.overview.jmhz_guidance'
  return {
    problemKey: `${prefix}.problems.${definition.message}`,
    stepKey: `${prefix}.steps.${definition.message}`,
    actionKey: `${prefix}.actions.${definition.destination}`,
    path: paths[definition.destination],
    agreementsPath: code === 'jmhz_ordinary_evidence_deduction_conflict'
      && (!context.reason || context.reason === 'missing_title') ? paths.agreements : null,
    fieldKey: code === 'jmhz_ordinary_evidence_monthly_exception_required'
      && exceptionFields.includes(context.field ?? '') ? `${prefix}.fields.${context.field}` : null,
    /** Výdělek OZP (10347, 10348) se doplňuje přímo u vztahu v panelu. */
    ozpWageForm: code === 'jmhz_ordinary_evidence_ozp_wage_required',
    /** Počet směn rizikové práce (10580) se doplňuje přímo u vztahu v panelu. */
    riskShiftForm: code === 'jmhz_ordinary_evidence_risk_shifts_required'
      || code === 'jmhz_ordinary_evidence_risk_shifts_invalid',
    versions: code === 'jmhz_ordinary_evidence_specification_mismatch' ? [
      ...(context.stored_xsd && context.current_xsd ? [{ key: `${prefix}.xsd_versions`, old: context.stored_xsd, current: context.current_xsd }] : []),
      ...(context.stored_controls && context.current_controls ? [{ key: `${prefix}.control_versions`, old: context.stored_controls, current: context.current_controls }] : []),
    ] : [],
  }
}

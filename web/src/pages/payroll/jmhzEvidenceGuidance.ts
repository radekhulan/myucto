import type { PayrollJmhzOrdinaryEvidenceScope } from '@/api/payroll'

type Destination = 'run' | 'revision' | 'employment' | 'deductions' | 'agreements' | 'support'
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
    support: '/admin/support',
  }
  if (definition.destination === 'employment') {
    const profile = code === 'jmhz_ordinary_evidence_monthly_exception_required'
    const field = profile
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
    versions: code === 'jmhz_ordinary_evidence_specification_mismatch' ? [
      ...(context.stored_xsd && context.current_xsd ? [{ key: `${prefix}.xsd_versions`, old: context.stored_xsd, current: context.current_xsd }] : []),
      ...(context.stored_controls && context.current_controls ? [{ key: `${prefix}.control_versions`, old: context.stored_controls, current: context.current_controls }] : []),
    ] : [],
  }
}

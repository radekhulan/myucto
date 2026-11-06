import { describe, expect, it } from 'vitest'
import { assessmentDraft, assessmentPayload, centsText, parseCents, legalProfileChoices, validateManualAssessment } from '@/utils/nonprofitTaxDraft'
import type { TaxSnapshot } from '@/api/nonprofitTax'
const snapshot: TaxSnapshot = { period: { id: 1, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31' }, input_hash: 'hash', findings: [], accounting_result_cents: -1, funding_reference_sources: {},
  sources: [{ id: 'line:1', line_id: 1, account_code: '518', date: '2026-01-01', kind: 'expense', amount_cents: 1, activity_kind_id: 3, activity: 'main', tax_regime: 'exempt', mandatory_non_deductible: false, axes: {}, origin: { type: 'manual', id: null, line_id: 1 } }] }
describe('nonprofit tax draft', () => {
  it('keeps missing activity evidence unresolved without inventing a null activity', () => {
    const incomplete = JSON.parse(JSON.stringify(snapshot)) as TaxSnapshot
    for (const source of incomplete.sources) Object.assign(source, { activity_kind_id: null, axes: [] })
    const draft = assessmentDraft(incomplete)
    expect(draft.activities).toEqual({})
    expect(draft.decisions['line:1']).toBeDefined()
  })
  it('preserves exact cents and negative corrections without floating arithmetic', () => {
    expect(parseCents('0,29')).toBe(29)
    expect(parseCents('-12345678.91')).toBe(-1234567891)
    expect(centsText(-1234567891)).toBe('-12345678.91')
    for (const invalid of ['', '0.001', '1e3', 'NaN', '90071992547409.92']) expect(() => parseCents(invalid)).toThrow('nonprofitTax.invalidAmount')
  })
  it('never infers main activity taxation or turns missing deductibility into zero', () => {
    const draft = assessmentDraft(snapshot)
    expect(draft.profile.kind).toBe('')
    expect(draft.decisions['line:1']?.title).toBe('')
    expect(assessmentPayload(draft).decisions['line:1']).not.toHaveProperty('deductible_cents')
    draft.decisions['line:1']!.deductible = '0'
    expect(assessmentPayload(draft).decisions['line:1']?.deductible_cents).toBe(0)
  })
  it('keeps gift elections and prior-period amounts separate from the bridge', () => {
    const draft = assessmentDraft(snapshot)
    draft.decisions['line:1']!.facts = { claim_exemption: false, withholding_cents: '0.21' }
    draft.funding_sources.push({ anchor: 'line:2', members: ['line:2', 'line:3'], evidence: 'Evidence', review: true, reviewEvidence: 'Prior year', reviewBasis: 'Own decision', reviewAmount: '-123.45' })
    const payload = assessmentPayload(draft)
    expect(payload.decisions['line:1']?.facts).toEqual({ claim_exemption: false, withholding_cents: 21 })
    expect(payload.funding_sources?.[0]?.prior_period_review?.adjustment_cents).toBe(-12345)
    expect(payload).not.toHaveProperty('assessed_result_cents')
  })
  it('rejects duplicate cohorts instead of overwriting evidence', () => {
    const draft = assessmentDraft(snapshot)
    draft.activities['3']!.cohorts = [{ code: 'same', evidence: 'First' }, { code: 'same', evidence: 'Second' }]
    expect(() => assessmentPayload(draft)).toThrow('nonprofitTax.invalidCohort')
  })
  it('offers only the confirmed SVJ profile without inventing a legal choice', () => {
    const fixed: TaxSnapshot = { ...snapshot, legal_profile: { id: 4, revision_no: 2, record_hash: 'proof', profile: { kind: 'svj', entity_type: 'svj', reduction_band: 'ineligible' } } }
    expect(legalProfileChoices(fixed)).toEqual(['svj'])
    const draft = assessmentDraft(fixed)
    expect(draft.profile.kind).toBe('')
    draft.profile.kind = 'narrow_vpp'
    expect(() => validateManualAssessment(draft, fixed)).toThrow('nonprofitTaxNative.profileConflict')
    draft.profile.kind = 'svj'
    expect(() => validateManualAssessment(draft, fixed)).not.toThrow()
  })
  it('retains explicit manual entity identity and qualified-cost purpose in the exact payload', () => {
    const manual: TaxSnapshot = { ...snapshot, assessment_support: { automatic: false, documented_manual: true, automatic_from: '2024-01-01', automatic_to: '2026-12-31' } }
    const draft = assessmentDraft(manual, { profile: { kind: 'documented', entity_kind: 'svj', evidence: 'Synthetic legal confirmation' }, activities: {}, decisions: {}, funding: [] })
    draft.decisions['line:1']!.facts.saving_qualified_purpose = 'education'
    expect(assessmentPayload(draft).profile.entity_kind).toBe('svj')
    expect(assessmentPayload(draft).decisions['line:1']!.facts!.saving_qualified_purpose).toBe('education')
  })
})

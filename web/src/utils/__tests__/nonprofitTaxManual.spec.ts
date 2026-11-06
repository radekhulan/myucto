import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { assessmentDraft, assessmentPayload, validateManualAssessment, type DecisionDraft } from '@/utils/nonprofitTaxDraft'
import type { TaxSnapshot } from '@/api/nonprofitTax'
import Editor from '@/components/accounting/nonprofitTax/SourceDecisionEditor.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
const snapshot = { period: { id: 7, fiscal_year: 2027, starts_on: '2027-01-01', ends_on: '2027-12-31' }, assessment_support: { automatic: false, documented_manual: true, automatic_from: '2024-01-01', automatic_to: '2026-12-31' }, input_hash: 'h', findings: [], accounting_result_cents: 7900, funding_reference_sources: {}, sources: [{ id: 'line:1', line_id: 1, account_code: '644', date: '2027-01-01', kind: 'revenue', amount_cents: 7900, activity_kind_id: 3, activity: 'main', tax_regime: 'subject', mandatory_non_deductible: false, axes: {}, origin: { line_id: 1, type: 'manual', id: null } }] } as TaxSnapshot

describe('documented manual assessment', () => {
  it('defaults unsupported years to an empty documented method without guessed final choices or an annual activity test', () => {
    const draft = assessmentDraft(snapshot)
    expect(draft).toHaveProperty('method', { mode: 'documented_manual', valid_from: '2027-01-01', valid_to: '2027-12-31', legal_basis: '', evidence: '' })
    expect(draft.profile).toEqual({ kind: 'documented', evidence: '' })
    expect(draft.activities).toEqual({ '3': { evidence: '', cohorts: [] } })
    expect(draft.decisions['line:1']).toHaveProperty('final_regime', '')
    expect(draft.decisions['line:1']).toHaveProperty('final_method', '')
  })
  it('offers final classification and withholding without automatic bank or membership facts', () => {
    const model: DecisionDraft = { title: 'bank_interest', evidence: '', legal_basis: '', deductible: '', facts: {}, final_regime: '', final_method: 'final_withholding' }
    const wrapper = mount(Editor, { props: { source: snapshot.sources[0]!, cohorts: [], manual: true, modelValue: model } })
    expect(wrapper.find('[data-test="final-regime"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="final-method"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('nonprofitTax.facts.gross_interest_cents')
    expect(wrapper.text()).not.toContain('nonprofitTax.facts.domestic_bank_account')
    expect(wrapper.text()).not.toContain('nonprofitTax.facts.foundation_endowment')
  })
  it('preserves final choices, exact withholding and a false gift election through serialization', () => {
    const draft = assessmentDraft(snapshot)
    Object.assign(draft, { method: { mode: 'documented_manual', valid_from: '2027-01-01', valid_to: '2027-12-31', legal_basis: 'Applicable law', evidence: 'Documented method' } })
    Object.assign(draft.decisions['line:1']!, { final_regime: 'subject', final_method: 'final_withholding', facts: { gross_interest_cents: '100.00', withholding_cents: '21.00', claim_exemption: false } })
    const payload = assessmentPayload(draft)
    expect(payload).toHaveProperty('method.mode', 'documented_manual')
    expect(payload.decisions['line:1']).toMatchObject({ final_regime: 'subject', final_method: 'final_withholding', facts: { gross_interest_cents: 10000, withholding_cents: 2100, claim_exemption: false } })
  })
})

function completedDraft() {
  const draft = assessmentDraft(snapshot)
  draft.method!.legal_basis = 'Period-specific provisions'
  draft.method!.evidence = 'Documented interpretation'
  draft.profile.evidence = 'Documented profile'
  draft.activities['3']!.evidence = 'Documented activity'
  Object.assign(draft.decisions['line:1']!, { title: 'bank_interest', legal_basis: 'Applicable interest provisions', evidence: 'Synthetic certificate', final_regime: 'subject', final_method: 'final_withholding', facts: { gross_interest_cents: '100.00', withholding_cents: '21.00' } })
  return draft
}
describe('manual form checks before server preview', () => {
  it('requires an interval covering the whole period and retains evidenced cohorts without performing the annual test', () => {
    const draft = completedDraft()
    expect(() => validateManualAssessment(draft, snapshot)).not.toThrow()
    draft.method!.valid_from = '2027-02-01'
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.intervalError')
    draft.method!.valid_from = '2027-01-01'
    draft.activities['3']!.cohorts.push({ code: 'separate', evidence: '' })
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.evidenceError')
    draft.activities['3']!.cohorts[0]!.evidence = 'Separate factual activity'
    expect(assessmentPayload(draft).activities['3']!.cohorts).toEqual({ separate: 'Separate factual activity' })
  })
  it('checks exact net withholding and refuses a mismatched cent', () => {
    const draft = completedDraft()
    draft.decisions['line:1']!.facts.withholding_cents = '21.01'
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.withholdingError')
    draft.decisions['line:1']!.facts.withholding_cents = '21.00'
    expect(() => validateManualAssessment(draft, snapshot)).not.toThrow()
  })
  it('requires an explicit gift election, keeps false distinct from absent and requires evidence for exemption', () => {
    const draft = completedDraft()
    const row = draft.decisions['line:1']!
    Object.assign(row, { title: 'gift', final_regime: 'subject', final_method: 'return', facts: {} })
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.giftError')
    row.facts.claim_exemption = false
    expect(() => validateManualAssessment(draft, snapshot)).not.toThrow()
    row.final_regime = 'exempt'; row.final_method = 'none'
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.giftError')
    row.facts.claim_exemption = true
    expect(() => validateManualAssessment(draft, snapshot)).toThrow('nonprofitTax.manual.giftError')
    row.facts.election_evidence = 'Explicit exemption election'
    expect(() => validateManualAssessment(draft, snapshot)).not.toThrow()
    const restored = assessmentDraft(snapshot, assessmentPayload(draft))
    expect(restored.method).toEqual(draft.method)
    expect(restored.decisions).toEqual(draft.decisions)
  })
  it('does not subtract funding from input deductibility and enforces sign, amount and excluded cost zero', () => {
    const costSnapshot: TaxSnapshot = { ...snapshot, sources: [{ ...snapshot.sources[0]!, kind: 'expense', amount_cents: -7900 }] }
    const draft = completedDraft()
    Object.assign(draft.decisions['line:1']!, { title: 'cost', deductible: '-79.00', final_method: undefined, facts: {} })
    expect(() => validateManualAssessment(draft, costSnapshot)).not.toThrow()
    draft.funding.push({ source_id: 'line:2', cost_id: 'line:1', amount: '-20.00', evidence: 'Corrective funding', overlap: '-20.00' })
    expect(assessmentPayload(draft).decisions['line:1']!.deductible_cents).toBe(-7900)
    draft.decisions['line:1']!.deductible = '-79.01'
    expect(() => validateManualAssessment(draft, costSnapshot)).toThrow('nonprofitTax.manual.deductibleError')
    draft.decisions['line:1']!.deductible = '0'
    draft.decisions['line:1']!.final_regime = 'exempt'
    expect(() => validateManualAssessment(draft, costSnapshot)).not.toThrow()
  })
})

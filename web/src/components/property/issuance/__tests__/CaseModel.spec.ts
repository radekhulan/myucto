import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import CaseIdentityEditor from '../CaseIdentityEditor.vue'
import { caseErrorOf, caseIdentityComplete, candidatesOf, continuationCase, distinctionComplete, emptyCaseIdentity, emptyIssue, normalizeCaseRequest } from '../issuanceModel'
import type { CaseCandidate, CaseFamily, IssueContext } from '@/api/propertyChargeIssuance'
import cs from '@/i18n/cs.json'
const filled = { schema: 1 as const, case_ref: 'A', occurrence_ref: 'B', title_ref: 'C', decision_ref: 'D', decided_on: '2026-01-02', evidence_ref: 'E' }
const candidate = (source_key: string) => ({ source_key, unit_id: 1, reasons: ['period_overlap'] }) as unknown as CaseCandidate
describe('case identity model', () => {
  it('starts empty and incomplete, never generating a human reference', () => {
    const empty = emptyCaseIdentity(); expect(Object.values(empty).filter(v => v === '')).toHaveLength(6); expect(caseIdentityComplete(empty)).toBe(false)
    const context = { as_of: 'x', version: { id: 1, preview: { calculation: { rates: [] } } } } as unknown as IssueContext
    expect(emptyIssue(context)).not.toHaveProperty('case_identity')
  })
  it('validates lengths after trimming and NFC folding of the required fields', () => {
    expect(caseIdentityComplete(filled)).toBe(true); expect(caseIdentityComplete({ ...filled, case_ref: '   ' })).toBe(false)
    expect(caseIdentityComplete({ ...filled, case_ref: 'x'.repeat(161) })).toBe(false); expect(caseIdentityComplete({ ...filled, decided_on: '2026-1-2' })).toBe(false)
  })
  it('requires exactly the server candidate set, sorted and de-duplicated', () => {
    const base = { legal_reason: 'r', evidence_ref: 'e' }
    expect(distinctionComplete({ ...base, against_source_keys: ['b', 'a', 'a'] }, [candidate('a'), candidate('b')])).toBe(true)
    expect(distinctionComplete({ ...base, against_source_keys: ['a'] }, [candidate('a'), candidate('b')])).toBe(false)
    expect(distinctionComplete({ ...base, against_source_keys: ['a', 'c'] }, [candidate('a')])).toBe(false)
    expect(distinctionComplete({ ...base, legal_reason: ' ', against_source_keys: ['a'] }, [candidate('a')])).toBe(false)
  })
  it('normalizes only the initial request and leaves continuations untouched', () => {
    const unit = { unit_id: 1, expected_revision: 0, previous_charge_id: null, liability_groups: [], notices: [], case_distinction: { against_source_keys: ['b', 'a', 'b'], legal_reason: ' r ', evidence_ref: 'e' } }
    const request = { issue_mode: 'initial', case_identity: { ...filled, case_ref: ' A ' }, units: [unit] } as never
    const normalized = normalizeCaseRequest(request); expect(normalized.case_identity!.case_ref).toBe('A'); expect(normalized.units[0]!.case_distinction).toEqual({ against_source_keys: ['a', 'b'], legal_reason: 'r', evidence_ref: 'e' })
    const continuation = { issue_mode: 'difference', case_identity: { ...filled, case_ref: ' A ' }, units: [unit] } as never; expect(normalizeCaseRequest(continuation).case_identity!.case_ref).toBe(' A ')
  })
  it('parses the real error envelope and rejects malformed candidate details', () => {
    const e = { response: { status: 422, data: { error: { code: 'property_charge_case_distinction_required', details: { unit_id: 1, case_candidates: [candidate('a')] } } } } }
    expect(caseErrorOf(e)).toMatchObject({ code: 'property_charge_case_distinction_required', status: 422 }); expect(candidatesOf(caseErrorOf(e).details)?.candidates).toHaveLength(1)
    expect(caseErrorOf(new Error('x'))).toEqual({ code: '', status: 0, details: {} })
    expect(candidatesOf({ unit_id: 1, case_candidates: [{ nope: 1 }] })).toBeNull(); expect(candidatesOf({ unit_id: '1', case_candidates: [] })).toBeNull()
  })
  it('copies root identity from the context family, omits it for legacy and flags conflicts', () => {
    const family = { case_identity: filled, case_distinction: null } as unknown as CaseFamily
    expect(continuationCase(family, {})).toEqual({ identity: filled }); expect(continuationCase(family, { case_identity: { ...filled, schema: 1 } })).toEqual({ identity: filled })
    expect(continuationCase(family, { case_identity: { ...filled, case_ref: 'other' } })).toBeNull()
    const legacy = { case_identity: null, case_distinction: null } as unknown as CaseFamily
    expect(continuationCase(legacy, {})).toEqual({}); expect(continuationCase(legacy, { case_identity: filled })).toBeNull(); expect(continuationCase(undefined, {})).toEqual({})
  })
  it('renders every field and locks them for a correction', () => {
    const wrapper = mount(CaseIdentityEditor, { props: { identity: { ...filled }, readonly: true }, global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })], stubs: { DateInput: { props: ['modelValue', 'readonly'], template: '<input :value="modelValue" :readonly="readonly" />' } } } })
    const fields = wrapper.findAll('[data-case]'); expect(fields).toHaveLength(6)
    expect(fields.every(f => (f.element as HTMLInputElement).readOnly)).toBe(true); expect(wrapper.text()).toContain(cs.propertyIssuance.case.immutableHelp)
  })
})

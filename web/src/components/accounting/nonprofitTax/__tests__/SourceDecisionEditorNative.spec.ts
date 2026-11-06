import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Editor from '../SourceDecisionEditor.vue'
import type { TaxSource } from '@/api/nonprofitTax'
import type { DecisionDraft } from '@/utils/nonprofitTaxDraft'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
const source: TaxSource = { id: 'line:1', line_id: 1, account_code: '649', date: '2026-01-01', kind: 'revenue', amount_cents: 10000, activity_kind_id: 2, activity: 'main', tax_regime: 'out_of_scope', mandatory_non_deductible: false, axes: {}, origin: { type: 'manual', id: null, line_id: 1 } }
const draft = (title = ''): DecisionDraft => ({ title, evidence: '', legal_basis: '', cohort: '', deductible: '', facts: {} })
describe('native SVJ source legal titles', () => {
  it('offers a service exclusion only for a canonically owned service source', () => {
    const owned = { ...source, svj_owner_facts: { owner: 'property_service_lifecycle' as const, kind: 'revenue' as const, legal_basis: 'zdp_18_2f_3', case_id: 3 } }
    const wrapper = mount(Editor, { props: { source: owned, cohorts: [], modelValue: draft(), profileKind: 'svj' } })
    expect(wrapper.findAll('option').map(option => option.attributes('value'))).toEqual(['', 'svj_service_payment'])
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('')
    expect(wrapper.get('[data-test="svj-owner-facts"]').text()).toContain('zdp_18_2f_3')
    wrapper.unmount()
    const manual = mount(Editor, { props: { source, cohorts: [], modelValue: draft(), profileKind: 'svj' } })
    expect(manual.findAll('option').map(option => option.attributes('value'))).not.toContain('svj_service_payment')
    manual.unmount()
  })
  it('does not ask an SVJ for a foundation exemption when documenting bank withholding', () => {
    const wrapper = mount(Editor, { props: { source, cohorts: [], modelValue: draft('bank_interest'), profileKind: 'svj' } })
    expect(wrapper.text()).toContain('nonprofitTax.facts.domestic_bank_account')
    expect(wrapper.text()).not.toContain('nonprofitTax.facts.foundation_endowment')
    wrapper.unmount()
  })
})

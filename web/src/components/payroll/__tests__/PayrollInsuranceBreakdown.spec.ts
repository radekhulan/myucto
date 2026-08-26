import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollRunResultPerson } from '@/api/payroll'
import type { PayrollInsuranceBreakdown } from '@/api/payrollInsurance'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

const breakdownMock = vi.fn()
vi.mock('@/api/payrollInsurance', () => ({
  payrollInsuranceApi: { breakdown: (...args: unknown[]) => breakdownMock(...args) },
}))

import PayrollInsuranceBreakdownComponent from '@/components/payroll/PayrollInsuranceBreakdown.vue'

function people(): PayrollRunResultPerson[] {
  return [
    { employee_id: 31, statutory: { person_reference: 'employee:31', status: 'calculated' } },
    { employee_id: 32, statutory: { person_reference: 'employee:32', status: 'calculated' } },
  ]
}

/**
 * Fixture je doslovný tvar odpovědi backendu — čísla musí sedět mezi sebou,
 * jinak by test procvičoval jiný stav, než jaký může reálně nastat.
 * Sociální: 30 000 × 7,1 % = 2 130. Zdravotní: 30 000 × 13,5 % = 4 050,
 * z toho zaměstnanec 1 350 a zaměstnavatel 2 700.
 */
function fixture(overrides: Partial<PayrollInsuranceBreakdown> = {}): PayrollInsuranceBreakdown {
  return {
    revision: { id: 9, run_id: 4, revision_no: 1, revision_kind: 'regular', status: 'approved' },
    person: { employee_id: 31, full_name: 'Syntetická osoba' },
    social: {
      available: true,
      unavailable_reason: null,
      status: 'calculated',
      calculation_date: '2026-06-30',
      ruleset_id: 'social-2026',
      ruleset_hash: 'a'.repeat(64),
      jurisdiction: 'czech_regime_verified',
      jurisdiction_evidence_reference: 'evidence:a1',
      working_pensioner_discount_evidence_reference: null,
      assessment_base: {
        participating_minor: 3_000_000,
        capped_minor: 3_000_000,
        year_to_date_before_month_minor: 0,
        annual_maximum_reduction_minor: 0,
        annual_maximum_applied: false,
      },
      employee: {
        contribution_step: {
          label: 'monthly-employee-social-insurance',
          input_minor_units: 3_000_000,
          rate: { decimal: '0.071', numerator: 71, denominator: 1000, scale: 3 },
          unrounded_numerator: 213_000_000,
          unrounded_denominator: 1000,
          rounding_mode: 'ceil',
          output_minor_units: 213_000,
        },
        before_discount_minor: 213_000,
        discount_step: null,
        working_pensioner_discount_minor: 0,
        contribution_minor: 213_000,
      },
      employer: {
        scope: 'company_month',
        allocation: {
          method: 'capped_assessment_base_share',
          not_allocatable_reason: null,
          residual_rule: 'largest_remainder',
          is_statutory_personal_amount: false,
          people_count: 2,
          company_assessment_base_minor: 6_000_000,
          company_contribution_minor: 744_000,
          person_assessment_base_minor: 3_000_000,
          person_minor: 372_000,
        },
        categories: [{
          category: 'ordinary',
          paragraph5a_letter: 'a',
          assessment_base_minor: 3_000_000,
          contribution_minor: 744_000,
          contribution_step: {
            label: 'monthly-employer-social-insurance-ordinary',
            input_minor_units: 3_000_000,
            rate: { decimal: '0.248', numerator: 248, denominator: 1000, scale: 3 },
            unrounded_numerator: 744_000_000,
            unrounded_denominator: 1000,
            rounding_mode: 'ceil',
            output_minor_units: 744_000,
          },
        }],
        contribution_step: {
          label: 'monthly-employer-social-insurance',
          input_minor_units: 3_000_000,
          rate: { decimal: '0.248', numerator: 248, denominator: 1000, scale: 3 },
          unrounded_numerator: 744_000_000,
          unrounded_denominator: 1000,
          rounding_mode: 'ceil',
          output_minor_units: 744_000,
        },
        assessment_base_minor: 3_000_000,
        contribution_before_discount_minor: 744_000,
        part_time_discount_base_minor: 0,
        part_time_discount_step: null,
        part_time_discount_minor: 0,
        contribution_minor: 744_000,
      },
      relationships: [{
        employment_id: 101,
        relationship_reference: 'employment:101',
        kind: 'employment',
        result_status: 'calculated',
        participation_status: 'participates',
        participation_income_minor: 3_000_000,
        group_income_minor: 3_000_000,
        threshold_minor: null,
        reason_codes: ['regular-employment'],
        assessment_base_minor: 3_000_000,
        capped_assessment_base_minor: 3_000_000,
        included_participation_components: ['BASE'],
        excluded_participation_components: [],
        included_assessment_base_components: ['BASE'],
        excluded_assessment_base_components: ['MEAL_ALLOWANCE'],
        part_time_employer_discount: 'not_claimed',
        employer_rate_category: 'ordinary',
        annual_maximum_allocation_order: 1,
      }],
      issues: [],
    },
    health: {
      available: true,
      unavailable_reason: null,
      status: 'calculated',
      calculation_date: '2026-06-30',
      ruleset_id: 'health-2026',
      ruleset_hash: 'b'.repeat(64),
      jurisdiction: 'czech_regime_verified',
      jurisdiction_evidence_reference: null,
      insurer: { status: 'verified', code: '111', evidence_reference: 'evidence:insurer' },
      assessment_base: {
        this_employer_minor: 3_000_000,
        other_employers_minor: 0,
        combined_minor: 3_000_000,
      },
      minimum: {
        statutory_monthly_minor: 2_130_000,
        effective_minor: 2_130_000,
        employment_calendar_days: 30,
        excluded_calendar_days: 0,
        applicable_calendar_days: 30,
        top_up_applied: false,
        top_up_base_minor: null,
        top_up_responsibility: 'employee',
        top_up_responsibility_source: 'statutory_default',
        top_up_employer_selection: 'unverified',
        top_up_responsibility_evidence_reference: null,
        selected_top_up_employer_evidence_reference: null,
        reduction_evidence: [],
        ppz_counted: true,
      },
      contribution: {
        rate_source: 'persisted',
        rate_reconstruction: null,
        standard_step: {
          label: 'monthly-health-insurance-standard',
          input_minor_units: 3_000_000,
          rate: { decimal: '0.135', numerator: 135, denominator: 1000, scale: 3 },
          unrounded_numerator: 405_000_000,
          unrounded_denominator: 1000,
          rounding_mode: 'ceil',
          output_minor_units: 405_000,
        },
        standard_minor: 405_000,
        employee_standard_minor: 135_000,
        employer_standard_minor: 270_000,
        top_up_step: null,
        employee_top_up_minor: 0,
        employer_top_up_minor: 0,
        employee_minor: 135_000,
        employer_minor: 270_000,
        total_minor: 405_000,
      },
      relationships: [{
        employment_id: 101,
        relationship_reference: 'employment:101',
        kind: 'employment',
        result_status: 'calculated',
        participation_status: 'participates',
        relationship_income_minor: 3_000_000,
        group_income_minor: 3_000_000,
        threshold_minor: null,
        reason_codes: ['regular-employment'],
        assessment_base_minor: 3_000_000,
        participating_assessment_base_minor: 3_000_000,
        included_participation_components: ['BASE'],
        excluded_participation_components: [],
        included_assessment_base_components: ['BASE'],
        excluded_assessment_base_components: [],
      }],
      other_employer_evidence: [],
      insurer_liabilities: [
        {
          insurer_code: '111',
          is_person_insurer: true,
          person_count: 1,
          assessment_base_minor: 3_000_000,
          employee_minor: 135_000,
          employer_minor: 270_000,
          total_minor: 405_000,
        },
        {
          insurer_code: '201',
          is_person_insurer: false,
          person_count: 1,
          assessment_base_minor: 1_800_000,
          employee_minor: 81_000,
          employer_minor: 162_000,
          total_minor: 243_000,
        },
      ],
      issues: [],
    },
    ...overrides,
  }
}

async function mountWith(payload: PayrollInsuranceBreakdown) {
  breakdownMock.mockReset()
  breakdownMock.mockResolvedValue(payload)
  const wrapper = mount(PayrollInsuranceBreakdownComponent, {
    props: { revisionId: 9, people: people(), personNames: { 31: 'Syntetická osoba' } },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollInsuranceBreakdown', () => {
  it('explains both contributions down to the rate and the rounding', async () => {
    const wrapper = await mountWith(fixture())

    expect(breakdownMock).toHaveBeenCalledWith(9, 31)
    expect(wrapper.find('[data-testid="social-breakdown"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="health-breakdown"]').exists()).toBe(true)
    // Sazba i obě zaokrouhlené částky musí být vidět — bez nich není co ověřit.
    const socialStep = wrapper.get('[data-testid="social-employee-step"]').text()
    expect(socialStep).toContain('payroll.runs.insurance.step_sentence')
    expect(socialStep).toContain('7,1')
    expect(wrapper.get('[data-testid="health-standard-step"]').text()).toContain('13,5')
    expect(wrapper.text()).toContain('payroll.runs.insurance.employer_scope_note')
    await wrapper.findAll('button')
      .find(button => button.text().includes('payroll.runs.insurance.relationships_show'))
      ?.trigger('click')
    expect(wrapper.text()).toContain('payroll.runs.insurance.reason.regular_relationship')
    expect(wrapper.text()).not.toContain('regular-employment')
  })

  it('shows that the annual maximum capped the social base', async () => {
    const payload = fixture()
    if (!payload.social.available) throw new Error('fixture')
    payload.social.assessment_base = {
      participating_minor: 3_000_000,
      capped_minor: 1_200_000,
      year_to_date_before_month_minor: 217_753_400,
      annual_maximum_reduction_minor: 1_800_000,
      annual_maximum_applied: true,
    }
    const wrapper = await mountWith(payload)

    expect(wrapper.get('[data-testid="social-annual-maximum"]').text())
      .toContain('payroll.runs.insurance.annual_maximum_applied')
  })

  it('shows the health top-up to the minimum assessment base', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    payload.health.assessment_base = {
      this_employer_minor: 1_000_000,
      other_employers_minor: 0,
      combined_minor: 1_000_000,
    }
    payload.health.minimum = {
      ...payload.health.minimum,
      top_up_applied: true,
      top_up_base_minor: 1_130_000,
    }
    payload.health.contribution = {
      ...payload.health.contribution,
      standard_step: {
        label: 'monthly-health-insurance-standard',
        input_minor_units: 1_000_000,
        rate: { decimal: '0.135', numerator: 135, denominator: 1000, scale: 3 },
        unrounded_numerator: 135_000_000,
        unrounded_denominator: 1000,
        rounding_mode: 'ceil',
        output_minor_units: 135_000,
      },
      standard_minor: 135_000,
      employee_standard_minor: 45_000,
      employer_standard_minor: 90_000,
      top_up_step: {
        label: 'monthly-health-insurance-minimum-top-up',
        input_minor_units: 1_130_000,
        rate: { decimal: '0.135', numerator: 135, denominator: 1000, scale: 3 },
        unrounded_numerator: 152_550_000,
        unrounded_denominator: 1000,
        rounding_mode: 'ceil',
        output_minor_units: 152_550,
      },
      employee_top_up_minor: 152_600,
      employer_top_up_minor: 0,
      employee_minor: 197_600,
      employer_minor: 90_000,
      total_minor: 287_600,
    }
    const wrapper = await mountWith(payload)

    const topUp = wrapper.get('[data-testid="health-minimum-top-up"]').text()
    expect(topUp).toContain('payroll.runs.insurance.health_top_up_detail')
    expect(topUp).toContain('payroll.runs.insurance.top_up_responsibility.employee')
    // Kdo doplatek hradí, je jedna věc; čím je to podložené, druhá. Bez původu
    // by po letech nešlo poznat, jestli to někdo prohlásil, nebo plyne ze zákona.
    expect(topUp).toContain(
      'payroll.runs.insurance.top_up_responsibility_source.statutory_default',
    )
  })

  it('u starší revize bez zaznamenaného původu nedomýšlí, čím byl doplatek podložený', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    // Tentýž stav jako v testu výš — jen bez zaznamenaného původu, jak ho mají
    // revize spočítané dřív, než klíč vznikl.
    payload.health.minimum = {
      ...payload.health.minimum,
      top_up_applied: true,
      top_up_base_minor: 1_130_000,
      top_up_responsibility_source: '',
    }
    const wrapper = await mountWith(payload)

    const topUp = wrapper.get('[data-testid="health-minimum-top-up"]').text()
    expect(topUp).toContain('payroll.runs.insurance.top_up_responsibility.employee')
    expect(wrapper.find('[data-testid="health-top-up-source"]').exists()).toBe(false)
  })

  it('splits the health liability across insurers and marks the person’s own', async () => {
    const wrapper = await mountWith(fixture())

    const rows = wrapper.get('[data-testid="health-insurers"]').findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(wrapper.get('[data-testid="insurer-111"]').text())
      .toContain('payroll.runs.insurance.insurer_of_person')
    expect(wrapper.get('[data-testid="insurer-201"]').text())
      .not.toContain('payroll.runs.insurance.insurer_of_person')
  })

  it('says in a sentence that a revision without stored results cannot be explained', async () => {
    const wrapper = await mountWith(fixture({
      social: { available: false, unavailable_reason: 'result_set_missing' },
      health: { available: false, unavailable_reason: 'person_missing' },
    }))

    expect(wrapper.get('[data-testid="social-unavailable"]').text())
      .toBe('payroll.runs.insurance.unavailable.result_set_missing')
    expect(wrapper.get('[data-testid="health-unavailable"]').text())
      .toBe('payroll.runs.insurance.unavailable.person_missing')
    // Prázdno ani nula se nesmí vydávat za vysvětlení.
    expect(wrapper.find('[data-testid="social-employee-step"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="health-standard-step"]').exists()).toBe(false)
  })

  it('says in a sentence that an older revision did not record the health rate', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    payload.health.contribution = {
      ...payload.health.contribution,
      rate_source: 'not_recorded',
      standard_step: null,
    }
    const wrapper = await mountWith(payload)

    expect(wrapper.get('[data-testid="health-rate-missing"]').text())
      .toBe('payroll.runs.insurance.health_rate_not_recorded')
    expect(wrapper.find('[data-testid="health-standard-step"]').exists()).toBe(false)
  })

  it('still reports a top-up whose base the revision did not store', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    payload.health.minimum = {
      ...payload.health.minimum,
      top_up_applied: true,
      top_up_base_minor: null,
    }
    payload.health.contribution = {
      ...payload.health.contribution,
      rate_source: 'not_recorded',
      standard_step: null,
      top_up_step: null,
      employee_top_up_minor: 152_600,
      employee_minor: 287_600,
    }
    const wrapper = await mountWith(payload)

    const note = wrapper.get('[data-testid="health-top-up-base-unknown"]').text()
    expect(note).toContain('payroll.runs.insurance.health_top_up_amount_only')
    // Nesmí se objevit věta s rozdílem — ten se neuložil a nula by lhala.
    expect(wrapper.text()).not.toContain('payroll.runs.insurance.health_top_up_detail')
  })

  /**
   * Rekonstruovaná sazba se NESMÍ tvářit jako uložená. Uživatel musí vidět, že
   * je dopočtená, a čím je doložená — jinak se o ni opře jako o uložený doklad.
   */
  it('says out loud that the rate was reconstructed, and from what', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    payload.health.contribution = {
      ...payload.health.contribution,
      rate_source: 'reconstructed',
      rate_reconstruction: {
        ruleset_id: 'cz-payroll-2026.health-insurance.v1',
        ruleset_version: '1.0.0',
        ruleset_hash: 'c'.repeat(64),
        parameter_key: 'total.rate',
        proof: 'ruleset_hash_and_amount_match',
        standard_reconstructed: true,
        top_up_reconstructed: false,
      },
    }
    const wrapper = await mountWith(payload)

    const note = wrapper.get('[data-testid="health-rate-reconstructed"]').text()
    expect(note).toContain('payroll.runs.insurance.health_rate_reconstructed')
    expect(note).toContain('cz-payroll-2026.health-insurance.v1')
    // Rozklad se ukazuje dál — je dokázaný shodou s uloženou částkou.
    expect(wrapper.get('[data-testid="health-standard-step"]').text()).toContain('13,5')
    // A rozhodně to není hlášeno jako chybějící sazba.
    expect(wrapper.find('[data-testid="health-rate-missing"]').exists()).toBe(false)
  })

  /**
   * Podíl osoby na pojistném zaměstnavatele je alokace, ne zákonná částka —
   * musí mít vlastní popisek i větu o metodě, ne splynout s ostatními částkami.
   */
  it('labels the employer share as an allocation, not a statutory amount', async () => {
    const wrapper = await mountWith(fixture())

    const allocation = wrapper.get('[data-testid="social-employer-allocation"]').text()
    expect(allocation).toContain('payroll.runs.insurance.allocation_title')
    expect(allocation).toContain('payroll.runs.insurance.allocation_note')
    expect(allocation).toContain('capped_assessment_base_share')
    expect(wrapper.find('[data-testid="allocation-blocked"]').exists()).toBe(false)
  })

  it('says why the employer contribution could not be allocated at all', async () => {
    const payload = fixture()
    if (!payload.social.available) throw new Error('fixture')
    payload.social.employer.allocation = {
      method: 'not_allocatable',
      not_allocatable_reason: 'assessment_base_missing',
      residual_rule: null,
      is_statutory_personal_amount: false,
      people_count: 2,
      company_assessment_base_minor: 0,
      company_contribution_minor: 744_000,
      person_assessment_base_minor: 0,
      person_minor: null,
    }
    const wrapper = await mountWith(payload)

    expect(wrapper.get('[data-testid="allocation-blocked"]').text())
      .toContain('payroll.runs.insurance.allocation_blocker.assessment_base_missing')
  })

  it('does not cry wolf when no contribution arose at all', async () => {
    const payload = fixture()
    if (!payload.health.available) throw new Error('fixture')
    payload.health.contribution = {
      rate_source: 'not_applicable',
      rate_reconstruction: null,
      standard_step: null,
      standard_minor: 0,
      employee_standard_minor: 0,
      employer_standard_minor: 0,
      top_up_step: null,
      employee_top_up_minor: 0,
      employer_top_up_minor: 0,
      employee_minor: 0,
      employer_minor: 0,
      total_minor: 0,
    }
    const wrapper = await mountWith(payload)

    expect(wrapper.get('[data-testid="health-not-applicable"]').text())
      .toBe('payroll.runs.insurance.health_no_contribution')
    expect(wrapper.find('[data-testid="health-rate-missing"]').exists()).toBe(false)
  })

  it('switches people and reloads the breakdown for the selected employee', async () => {
    const wrapper = await mountWith(fixture())

    const tabs = wrapper.findAll('nav button')
    expect(tabs).toHaveLength(2)
    await tabs[1].trigger('click')
    await flushPromises()

    expect(breakdownMock).toHaveBeenLastCalledWith(9, 32)
  })

  it('renders nothing without a revision', () => {
    breakdownMock.mockReset()
    const wrapper = mount(PayrollInsuranceBreakdownComponent, {
      props: { revisionId: null, people: people() },
    })

    expect(wrapper.find('[data-testid="insurance-breakdown"]').exists()).toBe(false)
    expect(breakdownMock).not.toHaveBeenCalled()
  })
})

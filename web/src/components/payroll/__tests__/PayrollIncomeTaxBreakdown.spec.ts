import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { PayrollRunResultPerson } from '@/api/payroll'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PayrollIncomeTaxBreakdown from '@/components/payroll/PayrollIncomeTaxBreakdown.vue'

function fixture(): PayrollRunResultPerson[] {
  return [
    {
      employee_id: 31,
      statutory: {
        person_reference: 'employee:31',
        status: 'calculated',
        income_tax: {
          status: 'calculated',
          calculation_date: '2026-08-31',
          employee_reference: 'employee:31',
          payer_reference: 'supplier:7',
          relationships: [{
            relationship_reference: 'employment:101',
            kind: 'employment',
            taxable_base_minor_units: 6_000_000,
            regime: 'advance',
            withholding_group: null,
          }, {
            relationship_reference: 'employment:102',
            kind: 'dpp',
            taxable_base_minor_units: 800_000,
            regime: 'withholding',
            withholding_group: 'dpp',
          }],
          advance_tax: {
            taxable_income_minor_units: 6_000_001,
            rounded_tax_base_minor_units: 6_010_000,
            low_rate_base_minor_units: 5_000_000,
            high_rate_base_minor_units: 1_010_000,
            rate_steps: [{
              label: 'monthly-advance-tax-low-rate',
              input_minor_units: 5_000_000,
              rate: { decimal: '0.15', numerator: 15, scale: 2, denominator: 100 },
              unrounded_numerator: 75_000_000,
              unrounded_denominator: 100,
              rounding_mode: 'toward-zero',
              output_minor_units: 750_000,
            }, {
              label: 'monthly-advance-tax-high-rate',
              input_minor_units: 1_010_000,
              rate: { decimal: '0.23', numerator: 23, scale: 2, denominator: 100 },
              unrounded_numerator: 23_230_000,
              unrounded_denominator: 100,
              rounding_mode: 'toward-zero',
              output_minor_units: 232_300,
            }],
            tax_before_credits_minor_units: 982_300,
            non_refundable_credits_minor_units: 257_000,
            child_credit_minor_units: 126_700,
            tax_bonus_eligible: true,
            tax_after_credits_minor_units: 598_600,
            tax_bonus_minor_units: 5_000,
            ruleset_id: 'income-tax-2026',
            ruleset_hash: 'synthetic-ruleset-hash',
          },
          withholding_groups: [{
            group: 'dpp',
            base_minor_units: 810_000,
            tax_minor_units: 121_500,
            rate_step: {
              label: 'monthly-withholding-tax-dpp',
              input_minor_units: 810_000,
              rate: { decimal: '0.15', numerator: 15, scale: 2, denominator: 100 },
              unrounded_numerator: 12_150_000,
              unrounded_denominator: 100,
              rounding_mode: 'floor',
              output_minor_units: 121_500,
            },
          }],
          withholding_base_minor_units: 810_000,
          withholding_tax_minor_units: 121_500,
          claimed_non_refundable_credits_minor_units: 300_000,
          applied_non_refundable_credits_minor_units: 257_000,
          claimed_child_credit_minor_units: 126_700,
          applied_child_credit_minor_units: 126_700,
          annual_accumulator: {},
          issues: [],
          policy_id: 'employment-income-tax-2026',
          policy_hash: 'synthetic-policy-hash',
          ruleset_id: 'income-tax-2026',
          ruleset_hash: 'synthetic-ruleset-hash',
        },
      },
    },
    {
      employee_id: 32,
      statutory: {
        person_reference: 'employee:32',
        status: 'manual_review',
        issues: ['tax-residence-unverified'],
        income_tax: {
          status: 'manual-review',
          calculation_date: '2026-08-31',
          employee_reference: 'employee:32',
          payer_reference: 'supplier:7',
          relationships: [{
            relationship_reference: 'employment:201',
            kind: 'statutory-body',
            taxable_base_minor_units: 450_000,
            regime: 'manual-review',
            withholding_group: null,
          }],
          advance_tax: null,
          withholding_groups: [],
          withholding_base_minor_units: 0,
          withholding_tax_minor_units: 0,
          claimed_non_refundable_credits_minor_units: 0,
          applied_non_refundable_credits_minor_units: 0,
          claimed_child_credit_minor_units: 0,
          applied_child_credit_minor_units: 0,
          annual_accumulator: {},
          issues: ['tax-residence-unverified', 'synthetic-new-review-reason'],
          policy_id: 'employment-income-tax-2026',
          policy_hash: 'synthetic-policy-hash',
          ruleset_id: 'income-tax-2026',
          ruleset_hash: 'synthetic-ruleset-hash',
        },
      },
    },
  ]
}

function scaledFixture(count: number): PayrollRunResultPerson[] {
  const base = fixture()[0]!
  return Array.from({ length: count }, (_, index) => {
    const employeeId = index + 1
    const statutory = structuredClone(base.statutory)!
    statutory.person_reference = `employee:${employeeId}`
    statutory.income_tax!.employee_reference = `employee:${employeeId}`
    return { employee_id: employeeId, statutory }
  })
}

describe('PayrollIncomeTaxBreakdown', () => {
  it('shows a complete advance and withholding tax calculation with responsive brackets', () => {
    const wrapper = mount(PayrollIncomeTaxBreakdown, {
      props: {
        people: fixture(),
        personNames: { 31: 'Syntetická osoba' },
      },
    })

    expect(wrapper.text()).toContain('Syntetická osoba')
    expect(wrapper.text()).toContain('payroll.runs.tax.regime.mixed')
    expect(wrapper.text()).toContain('payroll.runs.tax.rounding_title')
    expect(wrapper.text()).toContain('payroll.runs.tax.credits_title')
    expect(wrapper.text()).toContain('payroll.runs.tax.withholding_groups_title')
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('.md\\:hidden').exists()).toBe(true)
    expect(wrapper.text()).toContain('15')
    expect(wrapper.text()).toContain('23')
  })

  it('switches person tabs and explains manual review with known and unknown reasons', async () => {
    const wrapper = mount(PayrollIncomeTaxBreakdown, {
      props: { people: fixture() },
    })

    const tabs = wrapper.findAll('nav button')
    expect(tabs).toHaveLength(2)
    expect(tabs[1].text()).toContain('payroll.runs.tax.person_fallback')
    await tabs[1].trigger('click')

    expect(wrapper.get('[data-testid="tax-status"]').text())
      .toContain('payroll.runs.tax.status.manual_review')
    const reasons = wrapper.get('[data-testid="manual-review-reasons"]').text()
    expect(reasons).toContain('payroll.runs.tax.issues.tax-residence-unverified')
    expect(reasons).toContain('payroll.runs.tax.issues.unknown')
    expect(reasons.match(/tax-residence-unverified/g)).toHaveLength(1)
    expect(wrapper.text()).toContain('payroll.runs.tax.relationship_kind.statutory-body')
    expect(wrapper.text()).toContain('payroll.runs.tax.not_calculated')
  })

  it('u 500 daňových výsledků nevyrenderuje stovky záložek ani výsledků hledání', async () => {
    const people = scaledFixture(500)
    const personNames = Object.fromEntries(
      people.map(person => [person.employee_id, `Syntetická osoba ${person.employee_id}`]),
    )
    const wrapper = mount(PayrollIncomeTaxBreakdown, {
      props: { people, personNames },
    })

    expect(wrapper.find('[data-test="payroll-person-picker-tabs"]').exists()).toBe(false)
    const input = wrapper.get('[data-test="payroll-person-picker-search"] input[role="combobox"]')
    await input.trigger('focus')

    expect(wrapper.findAll('[role="option"]')).toHaveLength(25)
    expect(wrapper.find('[data-test="searchable-select-truncated"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="income-tax-breakdown"]').text())
      .toContain('Syntetická osoba 1')
  })

  it('renders nothing when the run snapshot has no income tax result', () => {
    const wrapper = mount(PayrollIncomeTaxBreakdown, {
      props: {
        people: [{ employee_id: 31 }],
      },
    })

    expect(wrapper.find('[data-testid="income-tax-breakdown"]').exists()).toBe(false)
  })
})

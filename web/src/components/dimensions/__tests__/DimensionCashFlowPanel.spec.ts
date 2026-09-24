import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DimensionCashFlowPanel from '../DimensionCashFlowPanel.vue'
import type { DimensionCashFlowReport } from '@/api/dimensions'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => v.toFixed(2) }))

function report(overrides: Partial<DimensionCashFlowReport> = {}): DimensionCashFlowReport {
  return {
    from: '2094-01-01',
    to: '2094-12-31',
    supplier_ids: [1],
    hidden_companies: 0,
    dimension: { type_id: 1, value_id: 5, value_ids: [5], label: 'Projekt: P5' },
    profit: 510,
    non_cash: { total: 90, accounts: [{ account_code: '082', name: 'Oprávky', amount: 90 }] },
    working_capital: { total: -750, accounts: [{ account_code: '311', name: 'Odběratelé', amount: -1210 }, { account_code: '343', name: 'DPH', amount: 210 }, { account_code: '321', name: 'Dodavatelé', amount: 250 }] },
    operating: -150,
    investing: { total: -2000, accounts: [{ account_code: '022', name: 'Stroje', amount: -2000 }] },
    financing: { total: 0, accounts: [] },
    net_cash_flow: -2150,
    cash_movement: -2150,
    untagged_cash: 0,
    reconciles: true,
    ...overrides,
  }
}

describe('DimensionCashFlowPanel', () => {
  it('skládá provozní, investiční a finanční tok a čistý tok', () => {
    const w = mount(DimensionCashFlowPanel, { props: { report: report() } })
    expect(w.find('[data-test="cf-profit"]').text()).toContain('510.00')
    expect(w.find('[data-test="cf-operating"]').text()).toContain('-150.00')
    expect(w.find('[data-test="cf-investing"]').text()).toContain('-2000.00')
    expect(w.find('[data-test="cf-net"]').text()).toContain('-2150.00')
    expect(w.text()).toContain('dimensions.cf_reconciles')
    expect(w.find('[data-test="cf-untagged-hint"]').exists()).toBe(false)
  })

  it('skupinu rozbalí na účty kliknutím, prázdnou skupinu ne', async () => {
    const w = mount(DimensionCashFlowPanel, { props: { report: report() } })
    expect(w.findAll('[data-test="cf-account"]')).toHaveLength(0)
    await w.find('[data-test="cf-working_capital"]').trigger('click')
    const accounts = w.findAll('[data-test="cf-account"]').map(tr => tr.text())
    expect(accounts).toHaveLength(3)
    expect(accounts[0]).toContain('311')
    expect(w.find('[data-test="cf-financing"]').exists()).toBe(false)
    expect(w.findAll('[data-test="cf-account"]')).toHaveLength(3)
  })

  it('rozdíl proti pohybu peněz vysvětlí podle toho, zda jde o dimenzi', () => {
    const tagged = mount(DimensionCashFlowPanel, { props: { report: report({ cash_movement: 0, untagged_cash: -2150, reconciles: false }) } })
    expect(tagged.find('[data-test="cf-untagged"]').text()).toBe('-2150.00')
    expect(tagged.find('[data-test="cf-untagged-hint"]').text()).toBe('dimensions.cf_untagged_hint')

    const company = mount(DimensionCashFlowPanel, { props: { report: report({ dimension: null, untagged_cash: 5, reconciles: false }) } })
    expect(company.find('[data-test="cf-untagged-hint"]').text()).toBe('dimensions.cf_unbalanced_hint')
  })
})

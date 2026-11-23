import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StatementAccountsTable from '@/components/accounting/StatementAccountsTable.vue'
import type { StatementAccountsReport, StatementColumnAccount, StatementColumnLine } from '@/api/accounting'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatDate: (v: string) => v,
}))

const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a><slot /></a>',
}

function cell(patch: Partial<StatementColumnAccount>): StatementColumnAccount {
  return {
    kind: 'synthetic', account_id: 1, account_code: '000', name: 'Účet', account_type: 'asset', normal_side: 'debit',
    ps: 0, month: 0, ytd: 0, ks: 0, ps_unusual: false, ks_unusual: false, is_zero: false, ...patch,
  }
}

const amounts = (ks: number) => ({ ps: 0, month: 0, ytd: ks, ks })

const balanceLines: StatementColumnLine[] = [
  {
    kind: 'cells',
    left: { kind: 'class', class: '2', ps: 0, month: 0, ytd: 3000, ks: 3000 },
    right: { kind: 'class', class: '3', ps: 0, month: 0, ytd: 500, ks: 500 },
  },
  {
    kind: 'cells',
    left: cell({ account_id: 20, account_code: '221', name: 'Banka', ks: 3000, ytd: 3000 }),
    right: cell({ account_id: 40, account_code: '321', name: 'Dodavatelé', account_type: 'liability', normal_side: 'credit', ks: 500, ytd: 500 }),
  },
  {
    kind: 'cells',
    left: cell({ kind: 'analytic', account_id: 22, account_code: '221002', name: 'Kontokorent', ks: -2000, ytd: -2000, ks_unusual: true }),
    right: null,
  },
  { kind: 'total', key: 'balance', left: amounts(3000), right: amounts(500) },
  { kind: 'result', key: 'profit_row', amounts: amounts(2500) },
  { kind: 'total', key: 'balance_with_result', left: amounts(3000), right: amounts(3000) },
]

const profitLines = (profit: number): StatementColumnLine[] => [
  { kind: 'section', key: 'operating' },
  {
    kind: 'cells',
    left: cell({ account_id: 50, account_code: '518', name: 'Služby', account_type: 'expense', ks: 2000, ytd: 2000 }),
    right: cell({ account_id: 60, account_code: '602', name: 'Tržby', account_type: 'revenue', normal_side: 'credit', ks: 0, ytd: 0 }),
  },
  { kind: 'total', key: 'section', section: 'operating', left: amounts(2000), right: amounts(0) },
  { kind: 'result', key: 'operating_profit', amounts: amounts(-2000) },
  { kind: 'result', key: 'profit_row', amounts: amounts(profit) },
]

function report(profit: number): StatementAccountsReport {
  return {
    version_code: 'test',
    as_of: '2099-06-30',
    entity: { name: 'Test', ico: null, address: null, legal_form: null, prepared_at: '2099-07-01 10:00' },
    period: { id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
    closed: false,
    balance: { classes: [], md: 5000, d: 2000, profit },
    profit_loss: { sections: [], operating_profit: -2000, financial_profit: 0, profit_before_tax: -2000, profit_after_tax: -2000, profit },
    checks: { profit_balance: profit, profit_loss: profit, profit_matches: true, technical_residual: 0, unassigned_count: 0 },
    columns: {
      as_of: '2099-06-30', month_from: '2099-06-01', ytd_from: '2099-01-01', hide_zero: true, analytics: true,
      balance: { lines: balanceLines, left_total: amounts(3000), right_total: amounts(500), result: amounts(2500) },
      profit_loss: { lines: profitLines(profit), left_total: amounts(2000), right_total: amounts(0), result: amounts(profit) },
    },
  }
}

function mountTable(part: 'balance' | 'profit_loss', profit = -2000, patch: Partial<StatementAccountsReport> = {}) {
  return mount(StatementAccountsTable, {
    props: { report: { ...report(profit), ...patch }, part, format: (v: number | null | undefined) => String(v), hideZero: true, showAnalytics: true },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

const mismatch = { profit_balance: 500, profit_loss: -2000, profit_matches: false, technical_residual: 0, unassigned_count: 0 }

describe('StatementAccountsTable', () => {
  it('dává aktiva vedle pasiv na jeden řádek', () => {
    const wrapper = mountTable('balance')
    expect(wrapper.get('[data-test="accounts-side-left"]').text()).toBe('accounting.statement_accounts.side_assets')
    expect(wrapper.get('[data-test="accounts-side-right"]').text()).toBe('accounting.statement_accounts.side_liabilities')
    const line = wrapper.findAll('[data-test="accounts-line"]')[1]
    expect(line.text()).toContain('221')
    expect(line.text()).toContain('321')
  })

  it('dává náklady vedle výnosů v rámci skupiny výsledovky', () => {
    const wrapper = mountTable('profit_loss')
    expect(wrapper.get('[data-test="accounts-side-left"]').text()).toBe('accounting.statement_accounts.side_expenses')
    expect(wrapper.get('[data-test="accounts-section"]').text()).toBe('accounting.statement_accounts.section_operating')
    const line = wrapper.get('[data-test="accounts-line"]')
    expect(line.text()).toContain('518')
    expect(line.text()).toContain('602')
  })

  it('má sloupce počáteční stav, obrat měsíce, obrat od začátku období a konečný stav', () => {
    const headers = mountTable('balance').findAll('thead tr')[1].text()
    expect(headers).toContain('accounting.statement_accounts.col_ps')
    expect(headers).toContain('accounting.statement_accounts.col_month')
    expect(headers).toContain('accounting.statement_accounts.col_ytd')
    expect(headers).toContain('accounting.statement_accounts.col_ks')
  })

  it('obarví červeně jen neobvyklou stranu', () => {
    const wrapper = mountTable('balance')
    expect(wrapper.get('[data-test="amount-221002-ks"]').classes()).toContain('text-danger-600')
    expect(wrapper.get('[data-test="amount-221002-ks"]').text()).toBe('-2000')
    expect(wrapper.get('[data-test="amount-221-ks"]').classes()).not.toContain('text-danger-600')
  })

  it('odkazuje ze syntetiky i analytiky na inventuru účtu k rozvahovému dni', () => {
    const links = mountTable('balance').findAllComponents(RouterLinkStub)
    expect(links.map(l => l.props('to').params.accountId)).toEqual([20, 40, 22])
    expect(links[0].props('to')).toEqual({
      name: 'accounting-account-statement',
      params: { accountId: 20 },
      query: { from: '2099-01-01', to: '2099-06-30', mode: 'inventory' },
    })
  })

  it('přepínače skrytí nulových účtů a analytik předá stránce', async () => {
    const wrapper = mountTable('balance')
    await wrapper.get('[data-test="accounts-zero-toggle"]').setValue(false)
    await wrapper.get('[data-test="accounts-analytics-toggle"]').setValue(false)
    expect(wrapper.emitted('update:hideZero')).toEqual([[false]])
    expect(wrapper.emitted('update:showAnalytics')).toEqual([[false]])
  })

  it('zvýrazní výsledek hospodaření podle znaménka', () => {
    const loss = mountTable('profit_loss').get('[data-test="accounts-profit-row"]')
    expect(loss.classes()).toContain('bg-danger-50')
    expect(loss.text()).toContain('accounting.statement_accounts.loss')
    const gain = mountTable('profit_loss', 1500).get('[data-test="accounts-profit-row"]')
    expect(gain.classes()).toContain('bg-success-50')
    expect(gain.text()).toContain('1500')
  })

  it('poznámku o účtech 702 a 710 ukáže jen u uzavřeného roku ke konci období', () => {
    expect(mountTable('balance', -2000, { closed: true }).find('[data-test="accounts-closed-note"]').exists()).toBe(false)
    expect(mountTable('balance', -2000, { closed: true, as_of: '2099-12-31' }).find('[data-test="accounts-closed-note"]').exists()).toBe(true)
  })

  it('nesoulad výsledku při filtru dimenze hlásí jako upozornění, ne chybu', () => {
    const plain = mountTable('balance', -2000, { checks: mismatch })
    expect(plain.find('[data-test="accounts-profit-mismatch"]').exists()).toBe(true)
    const dim = mountTable('balance', -2000, { checks: mismatch, dimension: { type_id: 1, value_id: 1, value_ids: [1] } })
    expect(dim.find('[data-test="accounts-profit-mismatch"]').exists()).toBe(false)
    expect(dim.find('[data-test="accounts-profit-mismatch-dimension"]').exists()).toBe(true)
  })
})

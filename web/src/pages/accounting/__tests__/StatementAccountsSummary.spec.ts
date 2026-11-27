import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { StatementAccountsReport, StatementColumnLine, YearEndTaxEstimate } from '@/api/accounting'

const m = vi.hoisted(() => ({ accounts: vi.fn(), estimate: vi.fn(), exportReport: vi.fn(), spreadOn: { value: true } }))
vi.mock('@/api/accounting', () => ({
  isStatement504: (value: { accounting_framework?: string }) => value.accounting_framework === 'cz_504',
  accountingApi: {
    listPeriods: async () => [{ id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open' }],
    getStatementAccounts: m.accounts,
    getYearEndTaxEstimate: m.estimate,
    exportReport: m.exportReport,
    getBalanceSheet: vi.fn(), getIncomeStatement: vi.fn(),
    getEntityCategory: async () => ({ category: 'micro', scope: 'micro' }),
  },
}))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: { view: 'accounts', period_id: '1', to: '2099-06-30' } }),
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key, locale: ref('cs') }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/composables/useAccrualSpread', () => ({
  useAccrualSpread: () => {
    const enabled = ref(m.spreadOn.value)
    return { enabled, restore: () => Promise.resolve(), set: vi.fn(), param: () => (enabled.value ? { accrual_spread: 1 } : {}) }
  },
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number | null) => value === null || value === undefined ? '' : String(value), formatDate: (value: string) => value }))

import BalanceSheet from '../BalanceSheet.vue'
import IncomeStatement from '../IncomeStatement.vue'

const amounts = (ks: number) => ({ ps: 0, month: 0, ytd: ks, ks })
const lines: StatementColumnLine[] = [
  { kind: 'result', key: 'profit_row', amounts: amounts(-1000000) },
]

function accountsReport(patch: Partial<StatementAccountsReport> = {}): StatementAccountsReport {
  return {
    version_code: 'test',
    as_of: '2099-06-30',
    entity: { name: 'Synthetic s.r.o.', ico: null, address: null, legal_form: null, prepared_at: '2099-07-01 10:00' },
    period: { id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
    closed: false,
    balance: { classes: [], md: 0, d: 0, profit: -1000000 },
    profit_loss: { sections: [], operating_profit: 0, financial_profit: 0, profit_before_tax: -1000000, profit_after_tax: -1000000, profit: -1000000 },
    checks: { profit_balance: -1000000, profit_loss: -1000000, profit_matches: true, technical_residual: 0, unassigned_count: 0 },
    columns: {
      as_of: '2099-06-30', month_from: '2099-06-01', ytd_from: '2099-01-01', hide_zero: true, analytics: true,
      balance: { lines, left_total: amounts(0), right_total: amounts(0), result: amounts(-1000000) },
      profit_loss: { lines, left_total: amounts(0), right_total: amounts(0), result: amounts(-1000000) },
    },
    accrual_spread: {
      applied: true, skipped_reason: null, prepaid_expense: 0, deferred_revenue: 1291099.41, documents: 1, depreciation: 0, depreciation_assets: 0, prev: null,
      accrual_agenda: 0, vh_effect: 1291099.41, later_dated: { deferred_revenue: 1291099.41, prepaid_expense: 0, depreciation: 0, entries: 1 },
    },
    ...patch,
  }
}

function estimate(patch: Partial<YearEndTaxEstimate> = {}): YearEndTaxEstimate {
  return {
    applicable: true, reason: null,
    period: { id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open' },
    vh_posted: 164099.61, vh_before_tax: 170000, vh_after_tax: 137700, tax: 32300, closing_items: [],
    bridge: { as_of: '2099-06-30', ends_on: '2099-12-31', vh_as_of: -1000000, vh_as_of_posted: -2291099.41, accrual_spread: 1291099.41, vh_posted: 164099.61,
      groups: [{ key: 'deferred_revenue', amount: 1164099.61, spread: 1291099.41 }] },
    ...patch,
  }
}

const stubs = { ActivationBanner: true, DimensionReportFilters: true, DimensionReportLinks: true, DateInput: true, EmptyState: true }
const pages = [['IncomeStatement', IncomeStatement, 'profit_loss'], ['BalanceSheet', BalanceSheet, 'balance']] as const

beforeEach(() => {
  m.spreadOn.value = true
  m.accounts.mockReset().mockResolvedValue(accountsReport())
  m.estimate.mockReset().mockResolvedValue(estimate())
  m.exportReport.mockReset().mockResolvedValue({ data: new Blob(['x']) })
})

describe.each(pages)('%s po účtech', (_name, page, part) => {
  it('nabídne průběžné rozlišení a pošle ho do výkazu, exportu i odhadu', async () => {
    const w = mount(page, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="accrual-spread-toggle"]').exists()).toBe(true)
    expect(m.accounts).toHaveBeenCalledWith(expect.objectContaining({ accrual_spread: 1, columns: 1 }))
    expect(w.find('[data-test="accrual-spread-note"]').exists()).toBe(true)
    expect(m.estimate).toHaveBeenCalledTimes(1)
    expect(m.estimate).toHaveBeenCalledWith(1, '2099-06-30', true)

    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const pdf = w.findAll('button').find(b => b.text().endsWith('.export_pdf'))!
    await pdf.trigger('click')
    await flushPromises()
    expect(m.exportReport).toHaveBeenCalledWith('/accounting/reports/statement-accounts/export',
      expect.objectContaining({ accrual_spread: 1, part, format: 'pdf' }))
  })

  it('nad tabulkou ukáže souhrn z téhož odhadu a konec tabulky doplní zápisy s pozdějším datem', async () => {
    const w = mount(page, { global: { stubs } })
    await flushPromises()
    const summary = w.get('[data-test="accounts-summary"]')
    expect(w.get('[data-test="accounts-summary-as-of"]').text()).toContain('-1000000')
    expect(w.find('[data-test="accounts-summary-spread-badge"]').exists()).toBe(true)
    const posted = w.get('[data-test="accounts-summary-posted"]')
    expect(posted.text()).toContain('{"date":"2099-12-31"}')
    expect(posted.text()).toContain('164099.61')
    expect(posted.classes()).toContain('border-primary-300')
    expect(w.get('[data-test="accounts-summary-before-tax"]').text()).toContain('170000')
    expect(w.get('[data-test="accounts-summary-after-tax"]').text()).toContain('137700')
    expect(summary.get('[data-test="accounts-summary-note"]').text()).toContain('accounting.statement_accounts.summary.note')
    expect(m.estimate).toHaveBeenCalledTimes(1)

    expect(w.get('[data-test="accounts-profit-later"]').findAll('td').at(-1)!.text()).toBe('1164099.61')
    expect(w.get('[data-test="accounts-profit-period"]').findAll('td').at(-1)!.text()).toBe('164099.61')
    expect(w.find('[data-test="year-end-estimate"]').exists()).toBe(true)
  })

  it('bez volby souhrn neukáže štítek a odhad se ptá bez průběžného rozlišení', async () => {
    m.spreadOn.value = false
    m.accounts.mockResolvedValue(accountsReport({ accrual_spread: undefined }))
    m.estimate.mockResolvedValue(estimate({ bridge: { as_of: '2099-06-30', ends_on: '2099-12-31', vh_as_of: -2291099.41, vh_as_of_posted: -2291099.41, accrual_spread: null, vh_posted: 164099.61, groups: [{ key: 'deferred_revenue', amount: 2455199.02 }] } }))
    const w = mount(page, { global: { stubs } })
    await flushPromises()
    expect(m.accounts.mock.calls[0]![0]).not.toHaveProperty('accrual_spread')
    expect(m.estimate).toHaveBeenCalledWith(1, '2099-06-30', false)
    expect(w.get('[data-test="accounts-summary-as-of"]').text()).toContain('-2291099.41')
    expect(w.find('[data-test="accounts-summary-spread-badge"]').exists()).toBe(false)
  })

  it('uzavřené období, filtr dimenze ani nepoužitelný odhad souhrn nemají', async () => {
    m.accounts.mockResolvedValue(accountsReport({ closed: true }))
    let w = mount(page, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="accounts-summary"]').exists()).toBe(false)
    expect(m.estimate).not.toHaveBeenCalled()

    m.accounts.mockResolvedValue(accountsReport({ dimension: { type_id: 1, value_id: 1, value_ids: [1] } }))
    w = mount(page, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="accounts-summary"]').exists()).toBe(false)

    m.accounts.mockResolvedValue(accountsReport())
    m.estimate.mockResolvedValue(estimate({ applicable: false, reason: 'income_tax_posted' }))
    w = mount(page, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="accounts-summary"]').exists()).toBe(false)
  })
})

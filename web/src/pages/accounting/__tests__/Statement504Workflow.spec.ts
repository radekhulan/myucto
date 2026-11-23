import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({ balance: vi.fn(), income: vi.fn(), accounts: vi.fn() }))
vi.mock('@/api/accounting', () => ({
  isStatement504: (value: { accounting_framework?: string }) => value.accounting_framework === 'cz_504',
  accountingApi: { listPeriods: async () => [{ id: 1, fiscal_year: 2099, starts_on: '2098-07-01', ends_on: '2099-06-30', status: 'open' }],
    getBalanceSheet: m.balance, getIncomeStatement: m.income, getStatementAccounts: m.accounts,
    getEntityCategory: async () => ({ category: 'micro', scope: 'micro' }) },
}))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' }, useRoute: () => ({ query: { period_id: '1', to: '2099-06-30' } }), useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useAccrualSpread', () => ({
  useAccrualSpread: () => ({ enabled: ref(false), restore: () => Promise.resolve(), set: vi.fn(), param: () => ({}) }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number | null) => value === null ? '' : String(value), formatDate: (value: string) => value }))

import BalanceSheet from '../BalanceSheet.vue'
import IncomeStatement from '../IncomeStatement.vue'

const base = { accounting_framework: 'cz_504', version_code: 'vyhl504-2002/2016', as_of: '2099-06-30', scope: 'full',
  period: { id: 1, fiscal_year: 2099, starts_on: '2098-07-01', ends_on: '2099-06-30' },
  entity: { name: 'Synthetic association', ico: '', address: '', prepared_at: '' } }
const stubs = { ActivationBanner: true, DimensionReportFilter: true, DimensionReportFilters: true, DimensionReportLinks: true, DateInput: true, EmptyState: true, YearEndTaxEstimate: true }

beforeEach(() => {
  m.balance.mockResolvedValue({ ...base, statement_type: 'balance_sheet',
    assets: [{ row_code: 'UR.36', display_code: 'A.IV.7.', label: 'Synthetic depreciation', start: 0, end: -250 }],
    liabilities: [{ row_code: 'US.7', display_code: 'A.II.1.', label: 'Synthetic result', start: null, end: 1500 }], checks: { balanced: true } })
  m.income.mockResolvedValue({ ...base, statement_type: 'income_statement',
    rows: [{ row_code: 'UT.63', display_code: 'D.', label: 'Synthetic result', main: -2000, economic: 3500, total: 1500 }], checks: { profit_current: 1500 } })
})

it('routes the actual balance page to signed start/end columns without 500 gross fields', async () => {
  const w = mount(BalanceSheet, { global: { stubs } })
  await flushPromises()
  const table = w.get('[data-test="statement-504"]')
  expect(table.text()).toContain('-250')
  expect(table.text()).toContain('1500')
  expect(table.text()).toContain('accounting.statement504.start')
  expect(table.text()).not.toContain('col_gross')
  const result = table.findAll('tr').find(row => row.text().includes('Synthetic result'))!
  expect(result.findAll('td')[2]!.text()).toBe('')
})

it('routes the actual income page to all three activity columns and preserves a loss', async () => {
  const w = mount(IncomeStatement, { global: { stubs } })
  await flushPromises()
  const table = w.get('[data-test="statement-504"]')
  expect(table.text()).toContain('accounting.statement504.main')
  expect(table.text()).toContain('accounting.statement504.economic')
  expect(table.text()).toContain('-2000')
  expect(table.text()).toContain('3500')
  expect(table.text()).toContain('1500')
})

it.each([BalanceSheet, IncomeStatement])('rounds a negative 504 half-thousand consistently with the PDF presentation', async (page) => {
  m.balance.mockResolvedValue({ ...base, statement_type: 'balance_sheet', assets: [],
    liabilities: [{ row_code: 'US.7', display_code: 'A.II.1.', label: 'Synthetic loss', start: null, end: -2500 }], checks: {} })
  m.income.mockResolvedValue({ ...base, statement_type: 'income_statement',
    rows: [{ row_code: 'UT.63', display_code: 'D.', label: 'Synthetic loss', main: -2500, economic: 0, total: -2500 }], checks: {} })
  const w = mount(page, { global: { stubs } })
  await flushPromises()
  await w.findAll('button').find(button => button.text() === 'reports.unit_thousands')!.trigger('click')
  const row = w.get('[data-test="statement-504"]').findAll('tr').find(row => row.text().includes('Synthetic loss'))!
  expect(row.findAll('td').at(-1)!.text()).toBe('-3')
})

it('shows separate current and deferred components and reconciles rounded amounts', async () => {
  m.income.mockResolvedValue({ ...base, statement_type: 'income_statement',
    deferred_tax_method: { enabled: true, first_adoption: false, version: '504-deferred-own-v1', reference: 'SYN-METHOD', disclosure: 'Synthetic own method', faithful_image_reason: 'Synthetic reason', standard_departure_reason: 'Synthetic own recognition', presentation_reason: 'Synthetic separate amounts' },
    rows: [{ row_code: 'UT.38', display_code: 'A.VIII.29', label: 'Synthetic tax total', main: 0, economic: 1000.02, total: 1000.02,
      components: [{ key: 'current_tax', label: 'Synthetic current tax', main: 0, economic: 500.01, total: 500.01 },
        { key: 'deferred_tax', label: 'Synthetic deferred tax', main: 0, economic: 500.01, total: 500.01 }] }], checks: {} })
  const w = mount(IncomeStatement, { global: { stubs } })
  await flushPromises()
  const table = w.get('[data-test="statement-504"]')
  expect(table.text()).toContain('Synthetic current tax')
  expect(table.text()).toContain('Synthetic deferred tax')
  expect(table.text()).toContain('SYN-METHOD')
  await w.findAll('button').find(button => button.text() === 'reports.unit_thousands')!.trigger('click')
  const values = table.findAll('tbody tr').map(row => Number(row.findAll('td').at(-1)!.text()))
  expect(values).toEqual([1, 0, 1])
})
